<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use YTassy\Tycms\CoreExts\Core\PageComponents\Video;
use YTassy\Tycms\Tests\Support\PageFixtures;
use YTassy\Tycms\CoreExts\Core\Helper\ShortCode\ShortCodeHelper;
use YTassy\Tycms\Lib\HtmlSanitizer\VideoEmbedUrl;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class PageVideoTest extends FrameworkTestCase
{
    public static function embedUrls(): iterable
    {
        yield ['https://youtu.be/Abc_def-123?t=12', 'https://www.youtube.com/embed/Abc_def-123'];
        yield ['https://www.youtube.com/watch?v=Abc_def-123&autoplay=1', 'https://www.youtube.com/embed/Abc_def-123'];
        yield ['https://www.youtube.com/shorts/Abc_def-123', 'https://www.youtube.com/embed/Abc_def-123'];
        yield ['HTTPS://vimeo.com/123456789', 'https://player.vimeo.com/video/123456789'];
        yield ['https://vimeo.com/123456789', 'https://player.vimeo.com/video/123456789'];
        yield ['https://player.vimeo.com/video/123456789?autoplay=1', 'https://player.vimeo.com/video/123456789'];
        yield ['https://www.youtube.com.evil.test/watch?v=Abc_def-123', null];
        yield ['https://www.youtube.com@evil.test/watch?v=Abc_def-123', null];
        yield ['javascript:alert(1)', null];
        yield ['http://vimeo.com/123', null];
        yield ['https://example.test/video.mp4', null];
        yield ['https://vimeo.com/0', null];
    }

    #[DataProvider('embedUrls')]
    public function testPlayerUrlsAreRebuiltFromAllowedProviders(string $url, ?string $expected): void
    {
        self::assertSame($expected, VideoEmbedUrl::normalize($url));
    }

    public function testEmbeddedVideoHasResponsiveControlsWithoutAutoplayOrRawQuery(): void
    {
        $renderer = new Video($this->createApplication());
        $html = $renderer->render((object) ['props' => (object) ['source' => 'url', 'src' => 'https://youtu.be/Abc_def-123?autoplay=1']], PageFixtures::renderContext());
        self::assertStringContainsString('class="ratio ratio-16x9"', $html);
        self::assertStringContainsString('src="https://www.youtube.com/embed/Abc_def-123"', $html);
        self::assertStringNotContainsString('autoplay', $html);
    }

    public function testBunnyUsesTheConfiguredHelperInThePageSpace(): void
    {
        $helper = $this->createMock(ShortCodeHelper::class);
        $helper->expects(self::once())->method('bunnyUrl')->with('123', 'abc-def', 7)
            ->willReturn('https://player.mediadelivery.net/embed/123/abc-def?token=signed&expires=123');
        $renderer = new Video(PageFixtures::application([ShortCodeHelper::class => $helper]));
        $html = $renderer->render((object) ['props' => (object) ['source' => 'bunny', 'library_id' => '123', 'video_id' => 'abc-def']], PageFixtures::renderContext());
        self::assertStringContainsString('token=signed&amp;expires=123', $html);
    }

    public static function invalidProps(): iterable
    {
        yield [(object) ['source' => 'arbitrary']];
        yield [(object) ['source' => 'url', 'src' => 'https://evil.test/player']];
        yield [(object) ['source' => 'media', 'src' => '//evil.test/video.mp4']];
        yield [(object) ['source' => 'media', 'src' => '/medias/test/%2e%2e/video.mp4']];
        yield [(object) ['source' => 'media', 'src' => '/medias/test/video.mp4" onerror="bad()']];
        yield [(object) ['source' => 'bunny', 'library_id' => 123, 'video_id' => 'abc']];
        yield [(object) ['source' => 'bunny', 'library_id' => '123', 'video_id' => '../other']];
    }

    #[DataProvider('invalidProps')]
    public function testInvalidSourcesAreRejected(object $props): void
    {
        $this->expectException(ExceptionResponse::class);
        Video::validate($props);
    }
}
