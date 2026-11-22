<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Tests\Support\PageFixtures;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderer;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\Response\ExceptionResponse;

final class PageVisibilityTest extends TestCase
{
    private function document(): object
    {
        return PageFixtures::decode('{"items":[
          {"id":"hidden_row","type":"core.row","props":{"common":{"visible":false}},"columns":[{"id":"secret_row_col","items":[{"type":"core.heading","props":{"text":"Secret row"}}]}]},
          {"id":"row","type":"core.row","columns":[
            {"id":"hidden_col","props":{"common":{"visible":false}},"items":[{"type":"core.heading","props":{"text":"Secret column"}}]},
            {"id":"column","items":[
              {"id":"hidden_heading","type":"core.heading","props":{"text":"Secret heading","common":{"visible":false}}},
              {"id":"visible_heading","type":"core.heading","props":{"text":"Visible"}},
              {"id":"hidden_ext","type":"test.extension","props":{"common":{"visible":false}}},
              {"id":"hidden_tabs","type":"core.tabs","props":{"common":{"visible":false}},"tabs":[{"id":"hidden_tabs_body","label":"Secret container","items":[]}]},
              {"id":"tabs","type":"core.tabs","props":{"default_tab":"hidden_tab"},"tabs":[
                {"id":"hidden_tab","label":"Secret tab","props":{"common":{"visible":false}},"items":[{"type":"core.heading","props":{"text":"Secret tab content"}}]},
                {"id":"visible_tab","label":"Visible tab","items":[]}
              ]},
              {"id":"accordion","type":"core.accordion","panels":[
                {"id":"hidden_panel","label":"Secret panel","props":{"common":{"visible":false}},"items":[{"type":"core.heading","props":{"text":"Secret panel content"}}]},
                {"id":"visible_panel","label":"Visible panel","items":[]}
              ]}
            ]}
          ]}
        ]}', false, 512, JSON_THROW_ON_ERROR);
    }

    public function testHiddenNodesAndDescendantsNeverEnterThePublicHtml(): void
    {
        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->id = 17;
        $page->space_id = 1;
        $page->type = 2;
        $page->raw_css = null;
        $page->data = PageFixtures::normalize($this->document());
        // Un composant d’extension masqué ne doit pas même consulter son renderer.
        $renderer = new PageRenderer(PageFixtures::application());
        $html = $renderer->render($page, 'fr')->getHtml();
        self::assertStringNotContainsString('Secret', $html);
        foreach (['hidden_row', 'hidden_col', 'hidden_heading', 'hidden_ext', 'hidden_tabs', 'hidden_tab', 'hidden_panel'] as $id) {
            self::assertStringNotContainsString('data-page-node="' . $id . '"', $html);
        }
        self::assertStringContainsString('<h2>Visible</h2>', $html);
        self::assertStringContainsString('Visible tab', $html);
        self::assertStringContainsString('Visible panel', $html);
        self::assertStringContainsString('id="page-17-tabs-tabs-visible_tab" aria-controls="page-17-tabs-tabs-visible_tab-panel" checked', $html);
    }

    public function testExplicitVisibilityDoesNotOverrideLanguageConditions(): void
    {
        $document = $this->document();
        $document->items[1]->zones->columns[1]->items[1]->props->common = (object) ['visible' => true, 'languages' => ['en']];
        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->id = 17;
        $page->space_id = 1;
        $page->type = 2;
        $page->raw_css = null;
        $page->data = $document;
        $renderer = new PageRenderer(PageFixtures::application());
        self::assertStringNotContainsString('<h2>Visible</h2>', $renderer->render($page, 'fr')->getHtml());
        self::assertStringContainsString('<h2>Visible</h2>', $renderer->render($page, 'en')->getHtml());
    }

    public static function invalidNodes(): iterable
    {
        yield 'row string' => ['row', 'false'];
        yield 'column integer' => ['column', 0];
        yield 'leaf null' => ['leaf', null];
        yield 'tab array' => ['tab', []];
        yield 'panel object' => ['panel', (object) []];
        yield 'extension string' => ['extension', 'true'];
    }

    #[DataProvider('invalidNodes')]
    public function testVisibilityMustBeBooleanForEveryNodeKind(string $kind, mixed $value): void
    {
        $document = $this->document();
        $items = $document->items[1]->zones->columns[1]->items;
        $node = match ($kind) {
            'row' => $document->items[0],
            'column' => $document->items[1]->zones->columns[0],
            'leaf' => $items[0],
            'tab' => $items[4]->zones->tabs[0],
            'panel' => $items[5]->zones->panels[0],
            'extension' => $items[2],
        };
        $node->props->common->visible = $value;
        $this->expectException(ExceptionResponse::class);
        PageFixtures::normalize($document);
    }

    public function testRenderNormalizationAlsoRejectsInvalidVisibility(): void
    {
        $document = $this->document();
        $document->items[0]->props->common->visible = 'false';
        $this->expectException(ExceptionResponse::class);
        PageFixtures::normalizeForRender($document);
    }

    public function testRegistryChecksVisibilityBeforeOtherCommonServices(): void
    {
        $document = $this->document();
        $document->items[0]->props->common->visible = 'false';
        $registry = PageFixtures::application()->getOrAddSharedExtensionsClass(PageComponentRegistry::class);
        $this->expectException(ExceptionResponse::class);
        $registry->validateDocument($document, 1);
    }
}
