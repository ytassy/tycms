<?php

namespace YTassy\Tycms\Lib\HtmlSanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/** Limits direction and anchor attributes added by editor controls. */
final class WysiwygTextAttributeSanitizer implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): ?array
    {
        return ['dir', 'id'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        if ($attribute === 'dir') {
            $direction = strtolower(trim($value));
            return in_array($direction, ['ltr', 'rtl'], true) ? $direction : null;
        }
        return $element === 'a' && preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,99}$/', $value) ? $value : null;
    }
}
