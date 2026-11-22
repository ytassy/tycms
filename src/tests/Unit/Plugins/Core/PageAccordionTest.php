<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Tests\Support\PageFixtures;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageAccordion;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageCommonStyles;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderer;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\Response\ExceptionResponse;

final class PageAccordionTest extends TestCase
{
    private function document(): object
    {
        return PageFixtures::decode('{"items":[{"id":"row","type":"core.row","columns":[{"id":"col","items":[
          {"id":"accordion","type":"core.accordion","props":{"mode":"custom","single_open":true,"open_panels":["first"],"accordion_style":{"tab_color":"#123456"}},"panels":[
            {"id":"first","label":{"fr":"<Premier>","en":"First"},"icon":"fa-solid fa-file","items":[
              {"id":"html","type":"core.html","props":{"content":"<p>Texte</p><script>alert(1)</script>","common":{"style":{"padding_top":"2px"}}}},
              {"id":"image","type":"core.image","props":{"src":"/medias/photo.png","alt":"Photo","click_action":"enlarge"}}
            ]},
            {"id":"second","label":"Second","items":[]}
          ]}
        ]}]}]}', false, 512, JSON_THROW_ON_ERROR);
    }

    private function render(object $document, string $locale = 'fr'): string
    {
        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->id = 17;
        $page->space_id = 1;
        $page->type = 2;
        $page->raw_css = null;
        $page->data = PageFixtures::normalize($document);
        $renderer = new PageRenderer(PageFixtures::application());
        return $renderer->render($page, $locale)->getHtml();
    }

    public function testNestedContentIsSanitizedAndStyledAndNativePanelsAreRendered(): void
    {
        $document = PageFixtures::normalize($this->document());
        self::assertStringContainsString('--pt-tab-color:#123456', PageAccordion::compile($document, 17));
        self::assertStringContainsString('padding-top:2px', PageCommonStyles::compile($document, 17));
        $html = $this->render($document);
        self::assertStringContainsString('name="page-17-accordion-accordion" open', $html);
        self::assertStringContainsString('&lt;Premier&gt;', $html);
        self::assertStringContainsString('fa-solid fa-file', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('data-page-image-src="/medias/photo.png"', $html);
        self::assertStringContainsString('aria-haspopup="dialog"', $html);
    }

    public function testMultipleModeCanOpenTwoPanelsAndHiddenContentIsOmitted(): void
    {
        $document = $this->document();
        $accordion = $document->items[0]->zones->columns[0]->items[0];
        $accordion->props->single_open = false;
        $accordion->props->open_panels = ['first', 'second'];
        $html = $this->render($document);
        self::assertSame(2, substr_count($html, 'class="page-accordion-panel" open'));
        self::assertStringNotContainsString('name="page-17-accordion-', $html);
        $accordion->zones->panels[0]->props = (object) ['common' => (object) ['languages' => ['fr']]];
        $english = $this->render($document, 'en');
        self::assertStringNotContainsString('Texte', $english);
        self::assertStringNotContainsString('Photo', $english);
        self::assertStringContainsString('Second', $english);
    }

    public function testImagesWithoutEnlargementAndUnsafeSourcesDoNotProduceLightboxTriggers(): void
    {
        $document = $this->document();
        $image = $document->items[0]->zones->columns[0]->items[0]->zones->panels[0]->items[1];
        $image->props->click_action = 'none';
        self::assertStringNotContainsString('data-page-image-lightbox', $this->render($document));
        $image->props->click_action = 'enlarge';
        $image->props->src = '/\\evil.test/photo.png';
        self::assertStringNotContainsString('data-page-image-lightbox', $this->render($document));
    }

    public function testArrowsCanBeHiddenOrUseACustomIconAndPosition(): void
    {
        $document = $this->document();
        $props = $document->items[0]->zones->columns[0]->items[0]->props;
        self::assertStringContainsString('fa-solid fa-chevron-right', $this->render($document));
        $props->show_arrow = false;
        self::assertStringNotContainsString('class="page-accordion-arrow"', $this->render($document));
        $props->show_arrow = true;
        $props->arrow_icon = 'fa-solid fa-plus';
        $props->arrow_position = 'left';
        $html = $this->render($document);
        self::assertStringContainsString('fa-solid fa-plus', $html);
        self::assertStringContainsString('page-accordion--arrow-left', $html);
        self::assertStringNotContainsString('fa-chevron-right', $html);
        $props->mode = 'simple';
        $props->arrow_rotation = -90;
        self::assertStringContainsString('--page-accordion-arrow-rotation:-90deg', PageAccordion::compile(PageFixtures::normalize($document), 17));
        $props->arrow_rotation = 0;
        self::assertStringContainsString('--page-accordion-arrow-rotation:0deg', PageAccordion::compile(PageFixtures::normalize($document), 17));
    }

    public function testEnlargementDoesNotApplyPercentageDimensionsTwice(): void
    {
        $document = $this->document();
        $props = $document->items[0]->zones->columns[0]->items[0]->zones->panels[0]->items[1]->props;
        $props->width = '50%';
        $props->max_width = '80%';
        $props->max_height = '300px';
        $html = $this->render($document);
        self::assertStringContainsString('style="width:50%;max-width:80%;max-height:300px;" aria-label="Agrandir', $html);
        self::assertStringContainsString('style="width:100%;max-height:300px;" loading="lazy"', $html);
    }

    public function testDeniedPanelRolePreventsRenderingItsImageAndContent(): void
    {
        $document = PageFixtures::normalize($this->document());
        $document->items[0]->zones->columns[0]->items[0]->zones->panels[0]->props->common = (object) ['role_ids' => [42]];
        $acl = $this->getMockBuilder(\YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper::class)
            ->disableOriginalConstructor()->onlyMethods(['hasGlobalRole'])->getMock();
        $acl->expects($this->once())->method('hasGlobalRole')->with(42, 1)->willReturn(false);
        $app = PageFixtures::application([\YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper::class => $acl]);
        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->id = 17;
        $page->space_id = 1;
        $page->type = 2;
        $page->raw_css = null;
        $page->data = $document;
        $html = (new PageRenderer($app))->render($page, 'fr')->getHtml();
        self::assertStringNotContainsString('Texte', $html);
        self::assertStringNotContainsString('data-page-image-lightbox', $html);
        self::assertStringContainsString('Second', $html);
    }

    public static function invalidCases(): iterable
    {
        foreach (['boolean', 'multiple', 'missing', 'duplicate', 'icon', 'css', 'image_boolean', 'node_limit', 'depth', 'arrow_boolean', 'arrow_icon', 'arrow_position', 'arrow_rotation', 'arrow_rotation_type'] as $case) yield $case => [$case];
    }

    #[DataProvider('invalidCases')]
    public function testMalformedDataIsRejected(string $case): void
    {
        $document = $this->document();
        $accordion = $document->items[0]->zones->columns[0]->items[0];
        switch ($case) {
            case 'arrow_rotation': $accordion->props->arrow_rotation = 361; break;
            case 'arrow_rotation_type': $accordion->props->arrow_rotation = '90;display:none'; break;
            case 'arrow_boolean': $accordion->props->show_arrow = 'false'; break;
            case 'arrow_icon': $accordion->props->arrow_icon = 'fa-solid fa-chevron-right" onclick="evil()'; break;
            case 'arrow_position': $accordion->props->arrow_position = 'above'; break;
            case 'boolean': $accordion->props->single_open = 'false'; break;
            case 'multiple': $accordion->props->open_panels = ['first', 'second']; break;
            case 'missing': $accordion->props->open_panels = ['absent']; break;
            case 'duplicate': $accordion->zones->panels[0]->items[0]->id = 'second'; break;
            case 'icon': $accordion->zones->panels[0]->icon = 'fa-solid fa-file" onmouseover="evil()'; break;
            case 'css': $accordion->props->accordion_style->tab_color = '#fff;}body{display:none'; break;
            case 'image_boolean': $accordion->zones->panels[0]->items[1]->props->click_action = 'invalid'; break;
            case 'node_limit': $accordion->zones->panels[0]->items = array_fill(0, 500, (object) ['type' => 'core.hr']); break;
            case 'depth':
                $current = $accordion;
                for ($i = 0; $i < 13; $i++) {
                    $nested = PageFixtures::decode(json_encode((object) ['type' => 'core.accordion', 'panels' => [(object) ['items' => []]]]));
                    $current->zones->panels[0]->items = [$nested];
                    $current = $nested;
                }
                break;
        }
        $this->expectException(ExceptionResponse::class);
        PageFixtures::normalize($document);
    }
}
