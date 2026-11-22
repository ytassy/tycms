<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageComponent;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageEditContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderer;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\PageFixtures;

final class PageComponentRegistryTest extends TestCase
{
    private function registry(): PageComponentRegistry
    {
        $registry = PageFixtures::application()->getOrAddSharedExtensionsClass(PageComponentRegistry::class);
        $registry->withOwner('plg_lms', fn () => $registry->register(PageCourseListFixture::class));
        return $registry;
    }

    public function testRegistrationAndCatalogueDoNotInstantiateComponents(): void
    {
        PageCourseListFixture::$instances = 0;
        $registry = $this->registry();
        $catalogue = $registry->catalogue();
        self::assertSame(0, PageCourseListFixture::$instances);
        $byId = array_column($catalogue, null, 'id');
        // Le catalogue évolue ; vérifier les inscriptions utiles plutôt qu’un total figé.
        self::assertArrayHasKey('core.iframe', $byId);
        self::assertArrayHasKey('lms.courses', $byId);
        self::assertSame('api', $byId['lms.courses']['canvasMode']);
    }

    public function testAllAddableTypesSupplyContextAndDeclarativeFormsAtOpening(): void
    {
        $editor = $this->registry()->prepareEditor(7);
        self::assertSame([['value' => 42, 'label' => 'Cours autorisé']], $editor['context']->plg_lms_categories);
        self::assertSame(42, $editor['forms']->{'lms.courses'}['initialValues']['category_id']);
    }

    public function testDeclaredFormControlsReferencesWithoutASeparateSchema(): void
    {
        $registry = $this->registry();
        $document = PageFixtures::decode('{"items":[{"id":"r","type":"core.row","columns":[{"id":"c","items":[{"id":"l","type":"lms.courses","props":{"category_id":99}}]}]}]}');
        $this->expectException(ExceptionResponse::class);
        $registry->normalizeDocument($document, 7);
    }

    public function testGenericPluginZoneIsValidatedAndRenderedThroughTheSameEngine(): void
    {
        $registry = $this->registry();
        $registry->withOwner('plg_lms', fn () => $registry->register(PageCourseContainerFixture::class));
        $document = PageFixtures::decode('{"items":[{"id":"r","type":"core.row","columns":[{"id":"c","items":[{"id":"l","type":"lms.container","props":{},"zones":{"lessons":[{"id":"body","props":{},"items":[{"id":"h","type":"core.heading","props":{"text":"Bonjour"}}]}]}}]}]}]}');
        $page = new Page($registry);
        $page->id = 4; $page->space_id = 7; $page->type = 2; $page->raw_css = null;
        $page->data = $registry->normalizeDocument($document, 7);
        $html = (new PageRenderer($registry))->render($page, 'fr')->getHtml();
        self::assertStringContainsString('<article>', $html);
        self::assertStringContainsString('<h2>Bonjour</h2>', $html);
        $page->data->items[0]->zones->columns[0]->items[0]->zones->lessons[0]->props->common = (object) ['visible' => false];
        self::assertStringNotContainsString('Bonjour', (new PageRenderer($registry))->render($page, 'fr')->getHtml());
    }

    public function testContextRejectsCrossPluginKeysAndCollisions(): void
    {
        $context = new PageEditContext(7);
        $context->add('plg_core', ['plg_core_menus' => []]);
        $this->expectException(TechException::class);
        $context->add('plg_lms', ['plg_core_menus' => []]);
    }

    public function testDuplicateComponentCannotReplaceTheOriginalDefinition(): void
    {
        $registry = $this->registry();
        $this->expectException(TechException::class);
        $registry->register(PageCourseListFixture::class);
    }
}

class PageCourseListFixture extends PageComponent
{
    public static int $instances = 0;
    public function __construct($app) { parent::__construct($app); self::$instances++; }
    public static function getId(): string { return 'lms.courses'; }
    public static function getLabel(): string { return 'Cours'; }
    public function getEditContext(PageEditContext $context): array
    {
        return ['plg_lms_categories' => [['value' => 42, 'label' => 'Cours autorisé']]];
    }
    public function getGeneralForm(PageEditContext $context): ?Form
    {
        return (new Form($this))->setId('lms.courses')->addSection('general', 'Général')
            ->addField('general', 'category_id', 'core.select', ['label' => 'Catégorie', 'default' => 42,
                'options' => $context->get('plg_lms_categories', [])]);
    }
    public function render(object $node, PageRenderContext $context): string { return '<p>Cours</p>'; }
}
class PageCourseContainerFixture extends PageComponent
{
    public static function getId(): string { return 'lms.container'; }
    public static function getLabel(): string { return 'Conteneur de cours'; }
    public static function getChildZones(): array { return ['lessons' => ['min' => 1, 'max' => 3]]; }
    public function render(object $node, PageRenderContext $context): string
    {
        $html = '<article>';
        foreach ($node->zones->lessons as $zone) $html .= $context->renderItems($zone->items);
        return $html . '</article>';
    }
}
