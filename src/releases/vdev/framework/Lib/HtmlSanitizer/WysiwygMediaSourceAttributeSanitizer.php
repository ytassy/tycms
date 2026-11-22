<?php

namespace YTassy\Tycms\Lib\HtmlSanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Applies separate source policies to images, videos and embedded players.
 */
final class WysiwygMediaSourceAttributeSanitizer implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return ['img', 'iframe', 'video'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['src'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return match ($element) {
            'img' => $this->isRelativeUrl($value) ? $value : null,
            'iframe' => VideoEmbedUrl::normalize($value),
            'video' => str_starts_with($value, '/medias/') ? $value : null,
        };
    }

    private function isRelativeUrl(string $url): bool
    {
        return !str_starts_with($url, '//')
            && preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) !== 1;
    }

}
