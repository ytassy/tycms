<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageDataMode;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderer;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceSwitchHelper;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\CoreExts\Core\PageComponents\SpaceSwitch;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\PageFixtures;

final class SpaceSwitchComponentTest extends TestCase
{
    public function testInputRejectsAnExplicitSpaceAbsentFromTheTrustedEditorContext(): void
    {
        $app = PageFixtures::application();
        $component = new SpaceSwitch($app);
        $this->expectException(ExceptionResponse::class);
        $component->validateData((object) ['space_ids' => [999]], new PageNormalizationContext(7, PageDataMode::INPUT));
    }

    public function testTheComponentCanBeSavedWithDefaultsAndLocalizedClosedText(): void
    {
        $spaces = $this->createStub(SpaceSwitchHelper::class);
        $spaces->method('editorOptions')->willReturn([['value' => 7, 'label' => 'Site']]);
        $app = PageFixtures::application([SpaceSwitchHelper::class => $spaces]);
        $document = PageFixtures::decode('{"items":[{"type":"row","columns":[{"items":[{"type":"space-switch","props":{"closed_text":{"fr":"Fermée","en":"Closed"}}}]}]}]}');
        $registry = $app->getOrAddSharedExtensionsClass(\YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry::class);
        $saved = $registry->normalizeDocument($document, 7);
        $props = $saved->items[0]->zones->columns[0]->items[0]->props;
        self::assertSame('Fermée', $props->closed_text->fr);
        self::assertSame('Closed', $props->closed_text->en);
        self::assertSame('user', $props->destination);
        self::assertSame([], $props->space_ids);
    }

    public function testRuntimeTokenIsSeparateFromPersistentPropsAndItsConfigurationCannotBeForged(): void
    {
        $app = PageFixtures::application();
        $app->getConf()->secret = 'unit-space-switch-secret';
        $component = new SpaceSwitch($app);
        $node = (object) ['props' => (object) SpaceSwitch::getDefaultData()];
        $public = $component->getPublicProps($node, PageFixtures::renderContext());
        $helper = new SpaceSwitchHelper($app);
        self::assertSame('user', $helper->readConfiguration($public->configToken)->destination);
        self::assertFalse(property_exists($node->props, 'configToken'));
        self::assertFalse(property_exists($public, 'space_ids'));
        self::assertNull($helper->readConfiguration($public->configToken . 'forged'));
        self::assertNull($helper->readConfiguration(str_repeat('x', 16001)));
    }

    public function testLogoAlignmentAndMaxWidthAreProjectedToThePublicProps(): void
    {
        $app = PageFixtures::application();
        $app->getConf()->secret = 'unit-space-switch-secret';
        $component = new SpaceSwitch($app);

        $props = array_replace(SpaceSwitch::getDefaultData(), ['logo_alignment' => 'center', 'logo_max_width' => 240]);
        $public = $component->getPublicProps((object) ['props' => (object) $props], PageFixtures::renderContext());
        self::assertSame('center', $public->logo_alignment);
        self::assertSame(240, $public->logo_max_width);

        // Un document historique sans ces réglages garde le rendu antérieur.
        $legacy = $component->getPublicProps((object) ['props' => (object) ['show_logo' => true]], PageFixtures::renderContext());
        self::assertSame('left', $legacy->logo_alignment);
        self::assertSame(0, $legacy->logo_max_width);
    }

    public function testPublicRenderingEscapesRuntimeJsonAndDoesNotPersistTheToken(): void
    {
        $app = PageFixtures::application();
        $app->getConf()->secret = 'unit-space-switch-secret';
        $page = new Page($app);
        $page->id = 9;
        $page->space_id = 7;
        $page->type = 2;
        $page->status = 1;
        $page->raw_css = null;
        $page->data = PageFixtures::decode('{"items":[{"type":"row","columns":[{"items":[{"type":"space-switch","props":{"closed_text":"</div><script>alert(1)</script>"}}]}]}]}');
        $html = (new PageRenderer($app))->render($page, 'fr')->getHtml();
        self::assertStringContainsString('data-page-vue="core/space-switch"', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('configToken', $html);
        self::assertStringNotContainsString('configToken', json_encode($page->data));
    }
}
