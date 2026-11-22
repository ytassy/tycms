<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Extensions\EmailRenderContext;
use YTassy\Tycms\CoreExts\MainTemplate\EmailRenderer;

final class EmailRendererTest extends TestCase
{
    public function testDefaultLayoutUsesConfiguredPresentationAndEscapesFooterText(): void
    {
        $html = EmailRenderer::render(
            '<h1>Bonjour</h1><p>Contenu</p>',
            (object) [
                'logo_email' => '/medias/main/logo.png',
                'email_logo_max_width' => 250,
                'email_logo_max_height' => 90,
                'email_default_outer_background' => '#EEEEEE',
                'email_default_content_background' => '#FAFAFA',
                'email_default_footer_background' => '#123456',
                'email_footer_text' => '<b>{space}</b> {year}',
                'email_footer_url' => '/contact',
                'email_footer_link_text' => 'Contact',
                'email_body_font' => 'georgia',
                'email_heading_color' => '#654321',
            ],
            'default',
            new EmailRenderContext('https://example.test', 'Mon site'),
        );

        self::assertStringContainsString('https://example.test/medias/main/logo.png', $html);
        self::assertStringContainsString('max-width:250px;max-height:90px', $html);
        self::assertStringContainsString('#EEEEEE', $html);
        self::assertStringContainsString('#FAFAFA', $html);
        self::assertStringContainsString('font-family:Georgia, Times New Roman, serif', $html);
        self::assertStringContainsString('color:#654321', $html);
        self::assertStringContainsString('&lt;b&gt;Mon site&lt;/b&gt; ' . date('Y'), $html);
        self::assertStringNotContainsString('<b>Mon site</b>', $html);
        self::assertStringContainsString('href="https://example.test/contact"', $html);
        self::assertStringContainsString('<h1>Bonjour</h1><p>Contenu</p>', $html);
    }

    public function testBlankLayoutOmitsFrameAndRejectsInvalidMode(): void
    {
        $context = new EmailRenderContext('https://example.test', 'Mon site');
        $html = EmailRenderer::render('<p>Simple</p>', (object) [
            'logo_email' => '/medias/main/logo.png',
            'email_blank_background' => '#EFEFEF',
        ], 'blank', $context);

        self::assertStringContainsString('background:#EFEFEF', $html);
        self::assertStringContainsString('<p>Simple</p>', $html);
        self::assertStringNotContainsString('logo.png', $html);
        self::assertStringNotContainsString('<table', $html);

        $this->expectException(InvalidArgumentException::class);
        EmailRenderer::render('x', (object) [], 'unknown', $context);
    }

    public function testFooterRejectsNonHttpsExternalUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EmailRenderer::render('<p>Message</p>', (object) [
            'email_footer_url' => 'javascript:alert(1)',
        ], 'default', new EmailRenderContext('https://example.test', 'Mon site'));
    }
}
