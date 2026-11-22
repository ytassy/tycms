<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Lib\HtmlSanitizer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Lib\HtmlSanitizer\HtmlSanitizer;

final class WysiwygFontSizeTest extends TestCase
{
    #[Test]
    public function itKeepsTheSizesOfferedByTinyMce(): void
    {
        foreach ([
            '50%', '60%', '70%', '80%', '90%', '110%', '120%', '130%', '140%', '150%', '160%',
            '0.7em', '0.8em', '0.9em', '1em', '1.1em', '1.2em', '1.3em', '1.4em', '1.5em', '1.6em', '1.7em',
        ] as $size) {
            self::assertSame(
                '<span style="font-size:' . $size . '">Texte</span>',
                HtmlSanitizer::sanitize('<span style="font-size: ' . $size . ';">Texte</span>'),
                $size,
            );
        }
    }

    #[Test]
    public function itRemovesOtherInlineStylesAndInvalidSizes(): void
    {
        self::assertSame(
            '<span style="font-size:0.7em">Texte</span>',
            HtmlSanitizer::sanitize('<span style="color:red; font-size:0.7em; background:url(javascript:alert(1))">Texte</span>'),
        );
        self::assertSame('<span>Texte</span>', HtmlSanitizer::sanitize('<span style="font-size:999em">Texte</span>'));
        self::assertSame('<p>Texte</p>', HtmlSanitizer::sanitize('<p style="font-size:1em">Texte</p>'));
    }
}
