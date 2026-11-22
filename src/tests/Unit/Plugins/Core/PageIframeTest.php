<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use YTassy\Tycms\CoreExts\Core\PageComponents\Iframe;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageEditContext;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;
use YTassy\Tycms\Tests\Support\PageFixtures;

final class PageIframeTest extends FrameworkTestCase
{
    public static function invalidParameters(): iterable
    {
        foreach ([
            'javascript:alert(1)', 'data:text/html,<script>alert(1)</script>',
            'http://example.test/embed', '//example.test/embed', '/administrator',
            'https://user:password@example.test/embed', 'https://example.test/" onload="bad()',
            "https://example.test/\nembed", 'https://example.test/\\embed',
            'https://', str_repeat('a', 4097), 123,
        ] as $url) {
            yield ['src', $url];
        }
        yield ['height', 79];
        yield ['height', 4001];
        yield ['height', '480'];
        yield ['height', 480.5];
        yield ['fullscreen', 'true'];
    }

    #[DataProvider('invalidParameters')]
    public function testUnsafeParametersAreRejected(string $field, mixed $value): void
    {
        $props = (object) Iframe::getDefaultData();
        $props->{$field} = $value;
        $this->expectException(ExceptionResponse::class);
        Iframe::validate($props);
    }

    public function testSlidesUrlRetainsItsParametersInsideAnIsolatedFrame(): void
    {
        $component = new Iframe(PageFixtures::application());
        $props = (object) Iframe::getDefaultData();
        $props->src = 'https://docs.google.com/presentation/d/e/example/embed?start=false&loop=false';
        $props->title = 'Présentation "Exemple" <test>';
        $html = $component->render((object) ['props' => $props], PageFixtures::renderContext());

        self::assertStringContainsString('start=false&amp;loop=false', $html);
        self::assertStringContainsString('&lt;test&gt;', $html);
        self::assertStringNotContainsString('title="Présentation "Exemple"', $html);
        self::assertStringContainsString('sandbox="allow-scripts allow-forms allow-presentation"', $html);
        self::assertStringNotContainsString('allow-same-origin', $html);
        self::assertStringNotContainsString('allow-popups', $html);
        self::assertStringNotContainsString('allow-top-navigation', $html);
        self::assertStringContainsString('referrerpolicy="no-referrer"', $html);
        self::assertStringContainsString('allowfullscreen', $html);
        self::assertStringContainsString('width:100%;height:480px', $html);
    }

    public function testAnyHttpsHostIsAcceptedAndFullscreenCanBeDisabled(): void
    {
        $component = new Iframe(PageFixtures::application());
        $props = (object) Iframe::getDefaultData();
        $props->src = 'https://another.example.test/embed';
        $props->fullscreen = false;
        $html = $component->render((object) ['props' => $props], PageFixtures::renderContext());
        self::assertStringContainsString($props->src, $html);
        self::assertStringNotContainsString('allowfullscreen', $html);
        self::assertStringNotContainsString('allow="fullscreen"', $html);
    }

    public function testRegistryNormalizesLocalizedTitlesAndRejectsExtraHtml(): void
    {
        $registry = PageFixtures::application()->getOrAddSharedExtensionsClass(PageComponentRegistry::class);
        $document = PageFixtures::decode('{"items":[{"id":"r","type":"core.row","columns":[{"id":"c","items":[{"id":"f","type":"core.iframe","props":{"src":"https://docs.google.com/presentation/d/e/example/embed","title":{"fr":"Présentation","en":"Slides"},"height":480,"fullscreen":true}}]}]}]}');
        $normalized = $registry->normalizeDocument($document, 1);
        $props = $normalized->items[0]->zones->columns[0]->items[0]->props;
        self::assertSame('Présentation', $props->title->fr);
        self::assertSame(480, $props->height);

        // Un collage ou un appel API ne peut pas injecter du HTML ou des permissions.
        $props->srcdoc = '<script>alert(1)</script>';
        $this->expectException(ExceptionResponse::class);
        $registry->normalizeDocument($normalized, 1);
    }

    public function testNewFrameIsEmptyAndItsFormIsAvailableInTheCatalogue(): void
    {
        $component = new Iframe(PageFixtures::application());
        self::assertSame('', $component->render((object) ['props' => (object) Iframe::getDefaultData()], PageFixtures::renderContext()));
        $definition = $component->getGeneralForm(new PageEditContext(1))->getDefForRia();
        self::assertNotEmpty($definition);
    }
}
