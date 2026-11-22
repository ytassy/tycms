<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Lib\HtmlSanitizer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Lib\HtmlSanitizer\HtmlSanitizer;

final class WysiwygSiteMediaTest extends TestCase
{
    #[Test]
    public function itKeepsSelectedSiteMedia(): void
    {
        self::assertSame(
            '<img src="/medias/site/photo.jpg" alt="Photo" />',
            HtmlSanitizer::sanitize('<img src="/medias/site/photo.jpg" alt="Photo">'),
        );
        self::assertSame(
            '<video controls="controls" src="/medias/site/film.mp4"></video>',
            HtmlSanitizer::sanitize('<video controls="controls" src="/medias/site/film.mp4"></video>'),
        );
        self::assertSame(
            '<a href="/medias/site/document.pdf" rel="noopener noreferrer">Document</a>',
            HtmlSanitizer::sanitize('<a href="/medias/site/document.pdf">Document</a>'),
        );
    }

    #[Test]
    public function itRejectsExternalVideoSourcesAndExtraAttributes(): void
    {
        self::assertSame(
            '<video controls="controls" src="/medias/site/film.mp4"></video>',
            HtmlSanitizer::sanitize('<video autoplay loop controls="controls" src="/medias/site/film.mp4"></video>'),
        );
        self::assertSame('', HtmlSanitizer::sanitize('<video controls="controls" src="https://other.example/film.mp4"></video>'));
    }
}
