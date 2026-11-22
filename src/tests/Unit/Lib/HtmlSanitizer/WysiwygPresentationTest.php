<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Lib\HtmlSanitizer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Lib\HtmlSanitizer\HtmlSanitizer;

final class WysiwygPresentationTest extends TestCase
{
    #[Test]
    public function itKeepsSimpleColorVariablesAndTheirCaseInTheCommonSanitizer(): void
    {
        $html = '<span style="color:var(--Brand_Color);background-color:var(--bs-primary)">Texte</span>';
        self::assertSame($html, HtmlSanitizer::sanitize($html));
        self::assertSame($html, HtmlSanitizer::sanitize(HtmlSanitizer::sanitize($html)));
        self::assertSame('<span style="color:#ff0000">Texte</span>', HtmlSanitizer::sanitize('<span style="COLOR:#FF0000">Texte</span>'));
        self::assertSame('<p>Texte</p>', HtmlSanitizer::sanitize('<p style="color:var(--Brand_Color)">Texte</p>'));
    }

    public static function unsafeColorVariables(): iterable
    {
        foreach (['var(--brand, red)', 'var(--brand, url(https://evil.test))', 'var( --brand)', 'var(--brand )',
            'var(--brand)/*comment*/', 'var(--brand) url(https://evil.test)', 'var(--brand) !important',
            'var(--)', 'var(--brand);background:url(javascript:alert(1))', 'expression(alert(1))'] as $value) yield [$value];
    }

    #[Test]
    #[DataProvider('unsafeColorVariables')]
    public function itDoesNotOpenTheInlineStylePolicyToCssExpressions(string $value): void
    {
        $html = HtmlSanitizer::sanitize('<span style="color:' . $value . '">Texte</span>');
        self::assertStringNotContainsString('url(', $html);
        self::assertStringNotContainsString('expression', $html);
        self::assertStringNotContainsString('!important', $html);
        self::assertStringNotContainsString('/*', $html);
        if (!str_contains($value, ';')) self::assertSame('<span>Texte</span>', $html);
        else self::assertSame('<span style="color:var(--brand)">Texte</span>', $html);
    }

    #[Test]
    public function itKeepsEditorColorsAndLineHeightsWithoutOtherCss(): void
    {
        self::assertSame(
            '<p style="line-height:1.5"><span style="color:#ff0000;background-color:rgb(0,128,255);font-size:1.2em">Texte</span></p>',
            HtmlSanitizer::sanitize('<p style="line-height: 1.5; position:absolute"><span style="color: #ff0000; background-color: rgb(0, 128, 255); font-size: 1.2em; font-family:serif">Texte</span></p>'),
        );
        self::assertSame(
            '<span>Texte</span>',
            HtmlSanitizer::sanitize('<span style="color:expression(alert(1)); background-color:rgb(256,0,0);font-family:serif">Texte</span>'),
        );
        self::assertSame('<p>Texte</p>', HtmlSanitizer::sanitize('<p style="line-height:99; color:#ff0000">Texte</p>'));
        self::assertSame(
            '<span style="color:rgba(255,0,10,0.5);background-color:#abc">Texte</span>',
            HtmlSanitizer::sanitize('<span style="color:rgba(255, 0, 10, 0.5);background-color:#abc">Texte</span>'),
        );
        self::assertSame('<span>Texte</span>', HtmlSanitizer::sanitize('<span style="color:rgba(0,0,0,2)">Texte</span>'));
        foreach (['1', '1.1', '1.2', '1.3', '1.4', '1.5', '2'] as $height) {
            self::assertSame(
                '<p style="line-height:' . $height . '">Texte</p>',
                HtmlSanitizer::sanitize('<p style="line-height:' . $height . '">Texte</p>'),
            );
        }
        self::assertSame(
            '<p style="text-align:center;line-height:1.4">Texte</p>',
            HtmlSanitizer::sanitize('<p style="text-align:center;line-height:1.4;position:absolute">Texte</p>'),
        );
        self::assertSame('<p>Texte</p>', HtmlSanitizer::sanitize('<p style="text-align:inherit">Texte</p>'));
    }

    #[Test]
    public function itKeepsSemanticContainersDirectionAndNamedAnchors(): void
    {
        self::assertSame(
            '<article><section><p dir="rtl">Texte <a id="partie-1" rel="noopener noreferrer">ici</a></p></section></article>',
            HtmlSanitizer::sanitize('<article><section><p dir="rtl">Texte <a id="partie-1">ici</a></p></section></article>'),
        );
        self::assertSame(
            '<p>Texte <a rel="noopener noreferrer">ici</a></p>',
            HtmlSanitizer::sanitize('<p dir="auto">Texte <a id="a mauvais id">ici</a></p>'),
        );
        self::assertSame(
            '<p><a href="#partie-1" rel="noopener noreferrer">Aller</a></p>',
            HtmlSanitizer::sanitize('<p><a href="#partie-1">Aller</a></p>'),
        );
    }
}
