<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Tests\Support\PageFixtures;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderer;
use YTassy\Tycms\CoreExts\Core\Models\Page;

final class PageRendererTest extends TestCase
{
    public function testRenderedPageUsesHtmlPurifiedBeforeSaving(): void
    {
        $draft = PageFixtures::decode(
            '{"items":[{"type":"core.row","columns":[{"items":[{"type":"core.html","props":{"content":"<p>Bonjour</p><script>alert(1)</script>"}},{"type":"core.rich-text","props":{"content":"<strong>Bienvenue</strong><script>alert(2)</script>"}}]}]}]}',
            false,
            512,
            JSON_THROW_ON_ERROR,
        );

        // La même normalisation est exécutée avant l'écriture en base et l'aperçu serveur.
        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->id = 7;
        $page->space_id = 1;
        $page->type = 2;
        $page->raw_css = null;
        $page->data = PageFixtures::normalize($draft);

        $renderer = new PageRenderer(PageFixtures::application());
        $html = $renderer->render($page)->getHtml();

        self::assertStringContainsString('<p>Bonjour</p>', $html);
        self::assertStringContainsString('<strong>Bienvenue</strong>', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testInitialPageFixtureRendersInV3(): void
    {
        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->id = 8;
        $page->space_id = 1;
        $page->type = 2;
        $page->raw_css = null;
        $page->data = PageFixtures::decode(
            '{"items":[{"type":"row","columns":[{"sizes":["col-md-12"],"items":[{"type":"html","content":"<p>Accueil</p>"}]}]}]}',
            false,
            512,
            JSON_THROW_ON_ERROR,
        );

        // La fixture est construite directement au format v3 pour le renderer.
        $renderer = new PageRenderer(PageFixtures::application());
        $html = $renderer->render($page)->getHtml();

        self::assertStringContainsString('class="col-md-12"', $html);
        self::assertStringContainsString('<p>Accueil</p>', $html);
    }

    public function testLocalizedCoreFieldsUseSelectedLanguageWithoutChangingStoredDocument(): void
    {
        $draft = PageFixtures::decode('{"items":[{"type":"core.row","columns":[{"items":['
            . '{"type":"core.heading","props":{"text":{"fr":"Bonjour","en":"Hello"}}},'
            . '{"type":"core.html","props":{"content":{"fr":"<p>Bienvenue</p>","en":"<p>Welcome</p>"}}},'
            . '{"type":"core.image","props":{"src":"/image.png","alt":{"fr":"Image","en":"Picture"}}},'
            . '{"type":"core.button","props":{"href":"/page","text":{"fr":"Voir","en":"View"}}}'
            . ']}]}]}', false, 512, JSON_THROW_ON_ERROR);

        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->id = 9;
        $page->space_id = 1;
        $page->type = 2;
        $page->raw_css = null;
        $page->data = PageFixtures::normalize($draft);

        $renderer = new PageRenderer(PageFixtures::application());
        $html = $renderer->render($page, 'en')->getHtml();

        self::assertStringContainsString('<h2>Hello</h2>', $html);
        self::assertStringContainsString('<p>Welcome</p>', $html);
        self::assertStringContainsString('alt="Picture"', $html);
        self::assertStringContainsString('>View</a>', $html);
        self::assertStringNotContainsString('Bienvenue', $html);
        self::assertSame('Bonjour', $page->data->items[0]->zones->columns[0]->items[0]->props->text->fr);
    }

    public function testMissingLocalizedVariantStaysEmpty(): void
    {
        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->id = 10;
        $page->space_id = 1;
        $page->type = 2;
        $page->raw_css = null;
        $page->data = PageFixtures::normalize(PageFixtures::decode('{"items":[{"type":"core.row","columns":[{"items":['
            . '{"type":"core.heading","props":{"text":{"fr":"Bonjour"}}}'
            . ']}]}]}', false, 512, JSON_THROW_ON_ERROR));

        $renderer = new PageRenderer(PageFixtures::application());
        $html = $renderer->render($page, 'en')->getHtml();

        self::assertStringContainsString('<h2></h2>', $html);
        self::assertStringNotContainsString('Bonjour', $html);
    }

    public function testLanguageVisibilityFiltersRowsColumnsAndComponents(): void
    {
        $document = PageFixtures::decode(json_encode(['items' => [
            ['id' => 'row_fr', 'type' => 'core.row', 'props' => ['common' => ['languages' => ['fr']]],
                'columns' => [['id' => 'column_fr', 'items' => [[
                    'id' => 'heading_fr_row', 'type' => 'core.heading', 'props' => ['text' => 'Ligne FR'],
                ]]]]],
            ['id' => 'row_mixed', 'type' => 'core.row', 'columns' => [
                ['id' => 'column_en', 'props' => ['common' => ['languages' => ['en']]], 'items' => [[
                    'id' => 'heading_en_column', 'type' => 'core.heading', 'props' => ['text' => 'Colonne EN'],
                ]]],
                ['id' => 'column_all', 'items' => [
                    ['id' => 'heading_fr', 'type' => 'core.heading',
                        'props' => ['common' => ['languages' => ['fr']], 'text' => 'Composant FR']],
                    ['id' => 'heading_all', 'type' => 'core.heading', 'props' => ['text' => 'Commun']],
                ]],
            ]],
        ]], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->id = 11;
        $page->space_id = 1;
        $page->type = 2;
        $page->raw_css = null;
        $page->data = PageFixtures::normalize($document);

        $renderer = new PageRenderer(PageFixtures::application());
        $french = $renderer->render($page, 'fr')->getHtml();
        $english = $renderer->render($page, 'en')->getHtml();

        self::assertStringContainsString('Ligne FR', $french);
        self::assertStringContainsString('Composant FR', $french);
        self::assertStringContainsString('Commun', $french);
        self::assertStringNotContainsString('Colonne EN', $french);
        self::assertStringContainsString('Colonne EN', $english);
        self::assertStringContainsString('Commun', $english);
        self::assertStringNotContainsString('Ligne FR', $english);
        self::assertStringNotContainsString('Composant FR', $english);
        self::assertSame(['fr'], $page->data->items[0]->props->common->languages);
    }
}
