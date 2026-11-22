<?php

namespace YTassy\Tycms\CoreExts\MainTemplate;

use InvalidArgumentException;
use YTassy\Tycms\Extensions\EmailRenderContext;

/** Email markup uses tables and inline presentation styles for mail clients. */
final class EmailRenderer
{
    private const FONTS = [
        'arial' => 'Arial, Helvetica, sans-serif',
        'georgia' => 'Georgia, Times New Roman, serif',
        'verdana' => 'Verdana, Geneva, sans-serif',
        'tahoma' => 'Tahoma, Geneva, sans-serif',
        'trebuchet' => 'Trebuchet MS, Arial, sans-serif',
    ];

    public static function render(string $content, object $params, string $mode, EmailRenderContext $context): string
    {
        if (!in_array($mode, ['blank', 'default'], true)) {
            throw new InvalidArgumentException('Mode de template e-mail inconnu.');
        }

        $bodyFont = self::font($params, 'email_body_font', 'arial');
        $headingFont = self::font($params, 'email_heading_font', 'arial');
        $bodySize = self::number($params, 'email_body_size', 16, 12, 24);
        $headingSize = self::number($params, 'email_heading_size', 24, 16, 40);
        $lineHeight = self::number($params, 'email_line_height', 1.5, 1, 2.5);
        $textColor = self::color($params, 'email_text_color', '#222222');
        $headingColor = self::color($params, 'email_heading_color', '#142B5B');
        $linkColor = self::color($params, 'email_link_color', '#0066CC');
        $commonStyle = 'font-family:' . $bodyFont . ';font-size:' . $bodySize . 'px;line-height:'
            . $lineHeight . ';color:' . $textColor . ';';
        $head = '<!doctype html><html lang="' . self::escape($context->locale) . '"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<style>.email-content h1,.email-content h2,.email-content h3,.email-content h4{font-family:'
            . $headingFont . ';font-size:' . $headingSize . 'px;color:' . $headingColor . ';line-height:1.25}'
            . '.email-content a{color:' . $linkColor . '}</style></head>';

        if ($mode === 'blank') {
            $background = self::color($params, 'email_blank_background', '#FFFFFF');
            return $head . '<body style="margin:0;padding:0;background:' . $background . ';">'
                . '<div class="email-content" style="background:' . $background . ';padding:16px;'
                . $commonStyle . '">' . $content . '</div></body></html>';
        }

        $outerBackground = self::color($params, 'email_default_outer_background', '#F0F0F0');
        $contentBackground = self::color($params, 'email_default_content_background', '#FFFFFF');
        $footerBackground = self::color($params, 'email_default_footer_background', '#142B5B');
        $footerColor = self::color($params, 'email_default_footer_color', '#FFFFFF');
        $dividerColor = self::color($params, 'email_default_divider_color', '#E6E6E6');
        $cardWidth = self::number($params, 'email_default_card_width', 630, 420, 800);
        $padding = self::number($params, 'email_default_content_padding', 30, 12, 60);
        $logoWidth = min(self::number($params, 'email_logo_max_width', 320, 40, 600), $cardWidth - 2 * $padding);
        $logoHeight = self::number($params, 'email_logo_max_height', 120, 20, 300);
        $logo = trim((string) ($params->logo_email ?? ''));
        $header = '';
        if ($logo !== '') {
            $logoUrl = self::absoluteUrl($logo, $context->siteUrl);
            $header = '<tr><td align="center" style="padding:30px ' . $padding . 'px 0;background:'
                . $contentBackground . ';">'
                . '<img src="' . self::escape($logoUrl) . '" alt="' . self::escape($context->spaceTitle)
                . '" style="display:block;width:auto;height:auto;max-width:' . $logoWidth
                . 'px;max-height:' . $logoHeight . 'px;border:0;"></td></tr>'
                . '<tr><td style="padding:28px ' . $padding . 'px 0;background:' . $contentBackground
                . ';"><div style="border-top:1px solid ' . $dividerColor . ';font-size:0;line-height:0;">&nbsp;</div></td></tr>';
        }

        $footerText = (string) ($params->email_footer_text ?? '© {space} {year}');
        $footerText = str_replace(
            ['{space}', '{year}'],
            [$context->spaceTitle, date('Y')],
            $footerText,
        );
        $footerUrl = self::absoluteUrl((string) ($params->email_footer_url ?? '/'), $context->siteUrl);
        $footerLinkText = trim((string) ($params->email_footer_link_text ?? ''));
        if ($footerLinkText === '') $footerLinkText = $footerUrl;
        $footer = self::escape($footerText) . '<br><a href="' . self::escape($footerUrl)
            . '" style="color:' . $footerColor . ';text-decoration:underline;">'
            . self::escape($footerLinkText) . '</a>';

        return $head . '<body style="margin:0;padding:0;background:' . $outerBackground . ';">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" '
            . 'style="border-collapse:collapse;background:' . $outerBackground . ';"><tr><td align="center" '
            . 'style="padding:30px 16px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" '
            . 'style="border-collapse:collapse;max-width:' . $cardWidth . 'px;background:' . $contentBackground . ';">'
            . $header
            . '<tr><td class="email-content" style="padding:' . $padding . 'px;background:'
            . $contentBackground . ';text-align:left;' . $commonStyle . '">'
            . $content . '</td></tr><tr><td align="center" style="padding:30px ' . $padding
            . 'px;background:' . $footerBackground . ';color:' . $footerColor . ';font-family:'
            . $bodyFont . ';font-size:13px;line-height:1.5;">' . $footer . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private static function absoluteUrl(string $url, string $siteUrl): string
    {
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')
            && !str_contains($url, "\r") && !str_contains($url, "\n")) {
            return rtrim($siteUrl, '/') . $url;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https') {
            return $url;
        }
        throw new InvalidArgumentException('URL du logo ou du pied de page e-mail invalide.');
    }

    private static function color(object $params, string $name, string $fallback): string
    {
        $value = $params->{$name} ?? null;
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/D', $value) === 1 ? $value : $fallback;
    }

    private static function font(object $params, string $name, string $fallback): string
    {
        $value = $params->{$name} ?? null;
        return is_string($value) && isset(self::FONTS[$value])
            ? self::FONTS[$value] : self::FONTS[$fallback];
    }

    private static function number(object $params, string $name, int|float $fallback, int|float $min, int|float $max): int|float
    {
        $value = $params->{$name} ?? null;
        return (is_int($value) || is_float($value)) && is_finite((float) $value)
            && $value >= $min && $value <= $max ? $value : $fallback;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
