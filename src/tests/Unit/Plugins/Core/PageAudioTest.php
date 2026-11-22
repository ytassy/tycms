<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderMode;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageDataMode;
use YTassy\Tycms\CoreExts\Core\PageComponents\Audio;
use YTassy\Tycms\CoreExts\Core\Helper\Media\MediaHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderer;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;
use YTassy\Tycms\Tests\Support\PageFixtures;

final class PageAudioTest extends FrameworkTestCase
{
    public static function invalidProps(): iterable
    {
        foreach (['https://evil.test/audio.mp3', '//evil.test/audio.mp3', '/medias/test/../secret',
            '/medias/test/%2e%2e/secret', '/medias/test/%5csecret', '/medias/test/a.mp3?autoplay=1',
            '/medias/test/a.mp3" onerror="alert(1)', '/medias/test/%00audio.mp3'] as $src) {
            yield $src => [(object) ['src' => $src]];
        }
        foreach ([(object) ['height' => 0], (object) ['height' => 201], (object) ['height' => '56'],
            (object) ['color' => 'red;display:none'], (object) ['accent_color' => 'url(https://evil.test)'],
            (object) ['border_radius' => '-1px'], (object) ['unknown' => '#fff'], [], null] as $index => $style) {
            yield 'style ' . $index => [(object) ['audio_style' => $style]];
        }
    }

    #[DataProvider('invalidProps')]
    public function testUnsafeSourcesAndStylesAreRejected(object $props): void
    {
        $this->expectException(ExceptionResponse::class);
        Audio::validate($props);
    }

    public function testPublicMountKeepsANativeFallbackAndPreviewRemainsIsolated(): void
    {
        $app = $this->createApplication();
        $space = new Space($app);
        $space->id = 7;
        $spaces = $this->createMock(SpaceHelper::class);
        $spaces->expects(self::exactly(2))->method('getSpace')->with(7)->willReturn($space);
        $media = $this->createMock(MediaHelper::class);
        $media->expects(self::exactly(2))->method('assertSelectableFile')->with($space, '/medias/test/mon%20audio.wav', 'audio');
        $registry = PageFixtures::initialize($app, [SpaceHelper::class => $spaces, MediaHelper::class => $media]);
        $page = new Page($app);
        $page->id = 12;
        $page->space_id = 7;
        $page->type = 2;
        $page->raw_css = null;
        $document = PageFixtures::decode('{"items":[{"type":"core.row","columns":[{"items":[{"type":"core.audio","props":{"src":"/medias/test/mon%20audio.wav","audio_style":{"height":72,"background_color":"#fff","border_radius":"12px"}}}]}]}]}');
        $page->data = $registry->normalizeDocument($document, 7, PageDataMode::RENDER);

        $renderer = new PageRenderer($app);
        $html = $renderer->render($page, 'fr')->getHtml();
        self::assertStringContainsString('data-page-vue="core/audio"', $html);
        self::assertStringContainsString('<audio src="/medias/test/mon%20audio.wav" controls preload="metadata"', $html);
        self::assertStringContainsString('min-height:72px', $html);
        self::assertStringNotContainsString('autoplay', $html);

        $preview = $renderer->render($page, 'fr', PageRenderMode::PREVIEW)->getHtml();
        self::assertStringContainsString('<audio ', $preview);
        self::assertStringNotContainsString('data-page-vue', $preview);
    }

    public function testMissingFileIsOmittedAndAnEmptyPlayerDoesNotReadMedia(): void
    {
        $app = $this->createApplication();
        $space = new Space($app);
        $space->id = 7;
        $spaces = $this->createMock(SpaceHelper::class);
        $spaces->expects(self::once())->method('getSpace')->with(7)->willReturn($space);
        $media = $this->createMock(MediaHelper::class);
        $media->expects(self::once())->method('assertSelectableFile')->willThrowException(new RuntimeException('Introuvable.'));
        PageFixtures::initialize($app, [SpaceHelper::class => $spaces, MediaHelper::class => $media]);
        $audio = new Audio($app);
        self::assertSame('', $audio->render((object) ['props' => (object) ['src' => '']], PageFixtures::renderContext()));
        self::assertSame('', $audio->render((object) ['props' => (object) ['src' => '/medias/test/absent.mp3']], PageFixtures::renderContext()));
    }
}
