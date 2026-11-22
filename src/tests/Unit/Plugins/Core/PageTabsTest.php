<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Tests\Support\PageFixtures;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageButtonStyles;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageCommonStyles;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageTabs;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderer;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\Response\ExceptionResponse;

final class PageTabsTest extends TestCase
{
    private function document(): object
    {
        return PageFixtures::decode('{"items":[{"id":"row","type":"core.row","columns":[{"id":"col","items":[
            {"id":"tabs","type":"core.tabs","props":{"mode":"custom","default_tab":"second","tabs_style":{"tab_color":"#080d55","body_padding_top":"2rem"}},"tabs":[
                {"id":"first","label":{"fr":"Premier","en":"<script>First</script>"},"icon":"fa-solid fa-file","items":[
                    {"id":"html","type":"core.html","props":{"content":"<p>Corps</p><script>alert(1)</script>"}},
                    {"id":"button","type":"core.button","props":{"text":"Lien","href":"/test","button_style":{"color":"#123456"},"common":{"style":{"padding_top":"2px"}}}}
                ]},
                {"id":"second","label":"Second","items":[]}
            ]}
        ]}]}]}', false, 512, JSON_THROW_ON_ERROR);
    }

    public function testNormalizationPurifiesNestedContentAndCompilersVisitBodies(): void
    {
        $document = PageFixtures::normalize($this->document());
        $tabs = $document->items[0]->zones->columns[0]->items[0];
        self::assertSame('<p>Corps</p>', $tabs->zones->tabs[0]->items[0]->props->content);
        self::assertStringContainsString('--pt-tab-color:#080d55', PageTabs::compile($document, 17));
        self::assertStringContainsString('[data-page-node="button"]', PageButtonStyles::compile($document, 17));
        self::assertStringContainsString('padding-top:2px', PageCommonStyles::compile($document, 17));
        $tabs->props->mode = 'simple';
        self::assertSame('', PageTabs::compile($document, 17));
    }

    public function testRendererEscapesLabelsAndUsesIndependentNativeGroupsForNestedTabs(): void
    {
        $document = PageFixtures::normalize($this->document());
        $tabs = $document->items[0]->zones->columns[0]->items[0];
        $nested = PageFixtures::decode('{"id":"nested","type":"core.tabs","tabs":[{"id":"nested_body","label":"Nested","items":[]}]}');
        $tabs->zones->tabs[0]->items[] = $nested;
        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->id = 17;
        $page->space_id = 1;
        $page->type = 2;
        $page->raw_css = null;
        $page->data = $document;
        $renderer = new PageRenderer(PageFixtures::application());
        $html = $renderer->render($page, 'en')->getHtml();
        self::assertStringContainsString('&lt;script&gt;First&lt;/script&gt;', $html);
        self::assertStringContainsString('fa-solid fa-file', $html);
        self::assertStringContainsString('id="page-17-tabs-tabs-second" aria-controls="page-17-tabs-tabs-second-panel" checked', $html);
        self::assertStringContainsString('name="page-17-tabs-nested"', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testHiddenDefaultTabDoesNotExposeItsContentAndFallsBackToVisibleTab(): void
    {
        $document = PageFixtures::normalize($this->document());
        $tabs = $document->items[0]->zones->columns[0]->items[0];
        $tabs->props->default_tab = 'first';
        $tabs->zones->tabs[0]->props->common = (object) ['languages' => ['fr']];
        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->id = 17;
        $page->space_id = 1;
        $page->type = 2;
        $page->raw_css = null;
        $page->data = $document;
        $renderer = new PageRenderer(PageFixtures::application());
        $html = $renderer->render($page, 'en')->getHtml();
        self::assertStringNotContainsString('Corps', $html);
        self::assertStringNotContainsString('page-17-tabs-tabs-first', $html);
        self::assertStringContainsString('id="page-17-tabs-tabs-second" aria-controls="page-17-tabs-tabs-second-panel" checked', $html);
    }

    public function testDeniedRoleOnTabBodyIsCheckedBeforeRenderingDescendants(): void
    {
        $document = PageFixtures::normalize($this->document());
        $document->items[0]->zones->columns[0]->items[0]->zones->tabs[0]->props->common = (object) ['role_ids' => [42]];
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
        self::assertStringNotContainsString('Corps', $html);
        self::assertStringNotContainsString('page-17-tabs-tabs-first', $html);
        self::assertStringContainsString('Second', $html);
    }

    public function testCanvasPreviewKeepsRoleRestrictedNodesVisibleForEditing(): void
    {
        $document = PageFixtures::normalize($this->document());
        $document->items[0]->zones->columns[0]->items[0]->props->common = (object) ['role_ids' => [42]];
        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->id = 17;
        $page->space_id = 1;
        $page->type = 2;
        $page->raw_css = null;
        $page->data = $document;
        $renderer = new PageRenderer(PageFixtures::application());

        // Le canevas montre le composant à l’éditeur même sans le rôle choisi.
        $canvas = $renderer->renderCanvas($page, 'tabs', 'fr');
        self::assertStringContainsString('Corps', $canvas);
        self::assertStringContainsString('Premier', $canvas);

        // L’aperçu fidèle et le rendu public continuent d’appliquer le rôle.
        self::assertStringNotContainsString('Corps', $renderer->render($page, 'fr')->getHtml());
    }

    public static function invalidInputs(): iterable
    {
        yield 'empty tabs' => ['empty'];
        yield 'duplicate id in descendants' => ['duplicate'];
        yield 'icon attribute injection' => ['icon'];
        yield 'CSS injection' => ['css'];
        yield 'unknown CSS property' => ['unknown'];
        yield 'invalid default tab' => ['default'];
        yield 'too many descendants' => ['count'];
        yield 'excessive nesting' => ['depth'];
    }

    #[DataProvider('invalidInputs')]
    public function testUntrustedTabDataIsRejected(string $case): void
    {
        $document = $this->document();
        $tabs = $document->items[0]->zones->columns[0]->items[0];
        switch ($case) {
            case 'empty': $tabs->zones->tabs = []; break;
            case 'duplicate': $tabs->zones->tabs[0]->items[0]->id = 'second'; break;
            case 'icon': $tabs->zones->tabs[0]->icon = 'fa-solid fa-file" onmouseover="alert(1)'; break;
            case 'css': $tabs->props->tabs_style->tab_color = '#fff;}body{display:none'; break;
            case 'unknown': $tabs->props->tabs_style->position = 'absolute'; break;
            case 'default': $tabs->props->default_tab = 'absent'; break;
            case 'count':
                $tabs->zones->tabs[0]->items = array_fill(0, 500, (object) ['type' => 'core.hr']);
                break;
            case 'depth':
                $current = $tabs;
                for ($i = 0; $i < 13; $i++) {
                    $child = PageFixtures::decode(json_encode((object) ['type' => 'core.tabs', 'tabs' => [(object) ['items' => []]]]));
                    $current->zones->tabs[0]->items = [$child];
                    $current = $child;
                }
                break;
        }
        $this->expectException(ExceptionResponse::class);
        PageFixtures::normalize($document);
    }

    public function testRegistryValidatesChildrenInsideTabs(): void
    {
        $document = PageFixtures::normalize($this->document());
        $button = $document->items[0]->zones->columns[0]->items[0]->zones->tabs[0]->items[1];
        unset($button->props->common);
        $button->props->button_style->color = 'red';
        $registry = PageFixtures::application()->getOrAddSharedExtensionsClass(PageComponentRegistry::class);
        $this->expectException(ExceptionResponse::class);
        $registry->validateDocument($document, 1);
    }
}
