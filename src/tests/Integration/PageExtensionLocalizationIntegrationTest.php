<?php

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Tests\Support\PageFixtures;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageComponent;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderer;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;
use YTassy\Tycms\Tools\HtmlTools;

final class PageExtensionLocalizationIntegrationTest extends TestCase
{
    public function testLocalizedExtensionUrlAndTextReachPhpAndVueAsStrings(): void
    {
        [$application, $registry, $seen] = $this->environment();
        $document = $this->document('/en/contact');

        $registry->validateDocument($document, TestFixtures::SITE_SPACE_ID);
        $props = $document->items[0]->zones->columns[0]->items[0]->props;

        self::assertSame('/fr/contact', $props->url->fr);
        self::assertSame('/en/contact', $props->url->en);
        self::assertSame('<p>Welcome</p>', $props->body->en);
        self::assertSame('LG_CORE_MENU_HOME', $props->title->en);
        self::assertSame('/fixed', $props->technical_url);
        self::assertSame(['/fr/contact', '/en/contact'], $seen->validated);

        $page = new Page($application);
        $page->id = 90;
        $page->space_id = TestFixtures::SITE_SPACE_ID;
        $page->type = 2;
        $page->raw_css = null;
        $page->data = $document;

        $html = (new PageRenderer($application))->render($page, 'en')->getHtml();

        self::assertStringContainsString('href="/en/contact"', $html);
        self::assertStringContainsString('>Home</a>', $html);
        self::assertStringContainsString('&quot;url&quot;:&quot;\/en\/contact&quot;', $html);
        self::assertStringContainsString('&quot;title&quot;:&quot;Home&quot;', $html);
        self::assertStringNotContainsString('/fr/contact', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertSame('/en/contact', $page->data->items[0]->zones->columns[0]->items[0]->props->url->en);
    }

    public function testEveryUrlVariantMustPassTheExtensionSchema(): void
    {
        [, $registry] = $this->environment();

        $this->expectException(ExceptionResponse::class);
        $registry->validateDocument($this->document('/not-contact'), TestFixtures::SITE_SPACE_ID);
    }

    private function environment(): array
    {
        $environment = TestEnvironment::load();
        TestFixtures::assertPrepared(new TestDatabase($environment));

        $application = new Application($environment->configuration());
        $application->initialiseOrThrow(new Request(uri: '/en/', host: TestFixtures::SITE_HOST));
        $registry = $application->getOrAddSharedExtensionsClass(PageComponentRegistry::class);
        $seen = (object) ['validated' => []];

        LocalizedPageTestComponent::$validated = [];
        $seen->validated = &LocalizedPageTestComponent::$validated;
        $registry->register(LocalizedPageTestComponent::class);

        return [$application, $registry, $seen];
    }

    private function document(string $englishUrl): object
    {
        return PageDocument::normalize(PageFixtures::decode(json_encode(['items' => [[
            'type' => 'core.row', 'columns' => [[
                'items' => [[
                    'type' => 'tests.localized-link',
                    'props' => [
                        'title' => ['fr' => 'Bienvenue', 'en' => 'LG_CORE_MENU_HOME'],
                        'url' => ['fr' => '/fr/contact', 'en' => $englishUrl],
                        'body' => ['fr' => '<p>Bonjour</p>',
                            'en' => '<p>Welcome</p><script>alert(1)</script>'],
                        'technical_url' => '/fixed',
                    ],
                ]],
            ]],
        ]]], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR));
    }
}


class LocalizedPageTestComponent extends PageComponent
{
    public static array $validated = [];
    public static function getId(): string { return 'tests.localized-link'; }
    public static function getLabel(): string { return 'Lien localisé'; }
    public static function getPublicVueComponentName(): ?string { return 'tests/localized-link'; }
    public static function getPropsSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'title' => ['type' => 'string', 'maxLength' => 100],
            'url' => ['type' => 'string', 'pattern' => '^/[a-z]{2}/contact$'],
            'body' => ['type' => 'string'], 'technical_url' => ['type' => 'string'],
        ], 'required' => ['title', 'url', 'body', 'technical_url']];
    }
    public static function getEditorFields(): array
    {
        return [
            ['name' => 'title', 'label' => 'Titre', 'type' => 'text', 'localized' => true],
            ['name' => 'url', 'label' => 'Lien', 'type' => 'url', 'localized' => true],
            ['name' => 'body', 'label' => 'Contenu', 'type' => 'html', 'localized' => true],
            ['name' => 'technical_url', 'label' => 'URL technique', 'type' => 'text'],
        ];
    }
    public function validateData(object $data, PageNormalizationContext $context): void
    {
        self::$validated[] = $data->url;
    }
    public function render(object $node, PageRenderContext $context): string
    {
        return '<a href="' . HtmlTools::escape($node->props->url) . '">'
            . HtmlTools::escape($node->props->title) . '</a>' . $node->props->body;
    }
}
