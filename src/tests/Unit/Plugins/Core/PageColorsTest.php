<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageDataMode;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageButtonStyles;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageCommonStyles;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageTabs;
use YTassy\Tycms\CoreExts\Core\Helper\Template\TemplateHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\PageComponents\Audio;
use YTassy\Tycms\CoreExts\Core\PageComponents\Support\PageColors;
use YTassy\Tycms\CoreExts\MainTemplate\Template;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\PageFixtures;

final class PageColorsTest extends TestCase
{
    public static function unsafeValues(): iterable
    {
        foreach (['var(--primary);display:none', 'var(--primary, red)', 'var(--primary, url(https://evil.test))',
            'var( --primary)', 'var(--primary )', 'var(--primary)/*x*/', 'url(https://evil.test)',
            'var(--primary);--x:url(https://evil.test)', "var(--primary)\n", "#fff\n", 'var(--)', 'red'] as $value) yield [$value];
    }

    #[DataProvider('unsafeValues')]
    public function testSharedGrammarRejectsCssExpressionsAndInjection(string $value): void
    {
        self::assertFalse(PageColors::isValid($value));
        $this->expectException(ExceptionResponse::class);
        PageButtonStyles::validate((object) ['button_style' => (object) ['background_color' => $value]]);
    }

    public function testCompilersAndAudioPreserveVariableReferences(): void
    {
        $variable = 'var(--bs-primary)';
        $props = (object) ['common' => (object) ['style' => (object) [
            'color' => $variable, 'link_color' => $variable, 'background_image' => '/cover.jpg', 'background_image_tint' => $variable,
        ]], 'button_style' => (object) ['background_color' => $variable],
            'tabs_style' => (object) ['tab_color' => $variable], 'audio_style' => (object) ['accent_color' => $variable]];
        PageCommonStyles::validate($props);
        PageButtonStyles::validate($props);
        PageTabs::validate($props);
        Audio::validate($props);

        $document = PageFixtures::document((object) ['items' => [(object) [
            'id' => 'row', 'type' => 'core.row', 'props' => $props, 'columns' => [(object) ['items' => []]],
        ]]]);
        $css = PageCommonStyles::compile($document, 12);
        self::assertStringContainsString('color:var(--bs-primary)', $css);
        self::assertStringContainsString('--page-link-color:var(--bs-primary)', $css);
        self::assertStringContainsString('::after{', $css);
        self::assertStringContainsString('background-color:var(--bs-primary)', $css);
        self::assertTrue(PageColors::hasVisibleTint($variable));
        self::assertFalse(PageColors::hasVisibleTint('#FFFFFF00'));
    }

    public function testRegistryUsesTheServerCatalogueForTheActualSpaceAndPreservesStoredReferences(): void
    {
        $template = $this->createMock(TemplateHelper::class);
        $template->expects(self::exactly(2))->method('getEditorColorVariables')->with(7)->willReturn([
            ['variable' => '--brand', 'label' => 'Marque'],
        ]);
        $app = PageFixtures::application([TemplateHelper::class => $template]);
        $registry = $app->getOrAddSharedExtensionsClass(\YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry::class);
        $document = PageFixtures::decode('{"items":[{"type":"core.row","columns":[{"props":{"common":{"style":{"color":"var(--brand)","link_color":"var(--brand)"}}},"items":[{"type":"core.button","props":{"button_style":{"hover_color":"var(--brand)"}}}]}]}]}');
        $normalized = $registry->normalizeDocument($document, 7);
        self::assertSame('var(--brand)', $normalized->items[0]->zones->columns[0]->props->common->style->color);
        self::assertSame('var(--brand)', $normalized->items[0]->zones->columns[0]->props->common->style->link_color);

        // Une référence dans une zone ou un composant enfant subit le même contrôle.
        $normalized->items[0]->zones->columns[0]->items[0]->props->button_style->hover_color = 'var(--undeclared)';
        $stored = $registry->normalizeDocument($normalized, 7, PageDataMode::EDITOR);
        self::assertSame('var(--undeclared)', $stored->items[0]->zones->columns[0]->items[0]->props->button_style->hover_color);
        $this->expectException(ExceptionResponse::class);
        $registry->normalizeDocument($stored, 7);
    }

    public function testTemplateCatalogueIsResolvedFromTheRequestedSpace(): void
    {
        $app = PageFixtures::application();
        $space = new Space($app);
        $space->template = 'main';
        $spaces = $this->createMock(SpaceHelper::class);
        $spaces->expects(self::once())->method('getSpace')->with(42)->willReturn($space);
        PageFixtures::initialize($app, [SpaceHelper::class => $spaces]);
        $template = new Template($app);
        $helper = $this->getMockBuilder(TemplateHelper::class)->setConstructorArgs([$app])->onlyMethods(['getTemplateExtension'])->getMock();
        $helper->expects(self::once())->method('getTemplateExtension')->with('main')->willReturn($template);
        self::assertContains(['variable' => '--bs-primary', 'label' => 'Principale'], $helper->getEditorColorVariables(42));
    }

    public function testCatalogueRejectsDuplicateNames(): void
    {
        $this->expectException(TechException::class);
        PageColors::normalizeCatalogue([
            ['variable' => '--brand', 'label' => 'Marque'], ['variable' => '--brand', 'label' => 'Doublon'],
        ]);
    }

    public function testCatalogueCannotInjectCssThroughAVariableName(): void
    {
        $this->expectException(TechException::class);
        PageColors::normalizeCatalogue([['variable' => '--brand);color:red', 'label' => 'Invalide']]);
    }
}
