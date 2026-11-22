<?php

namespace YTassy\Tycms\Lib\HtmlSanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/** Retains only the CSS declarations exposed by the rich text editor. */
final class WysiwygInlineStyleAttributeSanitizer implements AttributeSanitizerInterface
{
    private const FONT_SIZES = [
        '50%', '60%', '70%', '80%', '90%', '110%', '120%', '130%', '140%', '150%', '160%',
        '0.7em', '0.8em', '0.9em', '1em', '1.1em', '1.2em', '1.3em', '1.4em', '1.5em', '1.6em', '1.7em',
    ];
    private const LINE_HEIGHTS = ['1', '1.1', '1.2', '1.3', '1.4', '1.5', '2'];
    private const LINE_HEIGHT_ELEMENTS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'td', 'th', 'div'];
    private const TEXT_ALIGN_ELEMENTS = ['figure', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'td', 'th', 'tr', 'div', 'ul', 'ol', 'li', 'pre'];

    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): ?array
    {
        return ['style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $styles = [];
        foreach (explode(';', $value) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) continue;
            $property = strtolower(trim($parts[0]));
            $rawCandidate = trim($parts[1]);
            $candidate = strtolower($rawCandidate);

            if ($element === 'span' && $property === 'font-size' && in_array($candidate, self::FONT_SIZES, true)) {
                $styles[$property] = $candidate;
            } elseif ($element === 'span' && in_array($property, ['color', 'background-color'], true)) {
                $color = $this->normalizeColor($rawCandidate);
                if ($color !== null) $styles[$property] = $color;
            } elseif (in_array($element, self::LINE_HEIGHT_ELEMENTS, true) && $property === 'line-height'
                && in_array($candidate, self::LINE_HEIGHTS, true)) {
                $styles[$property] = $candidate;
            } elseif (in_array($element, self::TEXT_ALIGN_ELEMENTS, true) && $property === 'text-align'
                && in_array($candidate, ['left', 'center', 'right', 'justify'], true)) {
                $styles[$property] = $candidate;
            } elseif ($element === 'img' && $property === 'float'
                && in_array($candidate, ['left', 'right', 'none'], true)) {
                $styles[$property] = $candidate;
            }
        }

        if ($styles === []) return null;
        $declarations = [];
        foreach ($styles as $property => $value) $declarations[] = $property . ':' . $value;
        return implode(';', $declarations);
    }

    private function normalizeColor(string $value): ?string
    {
        // Les noms de variables sont sensibles à la casse. Ne pas normaliser leur contenu.
        if (CssColorVariable::name($value) !== null) return $value;

        $value = strtolower($value);
        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $value)) return $value;
        if (!preg_match('/^(rgb|rgba)\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*(0(?:\.\d{1,3})?|1(?:\.0{1,3})?))?\s*\)$/', $value, $matches)) return null;
        if (($matches[1] === 'rgb' && isset($matches[5])) || ($matches[1] === 'rgba' && !isset($matches[5]))) return null;
        for ($i = 2; $i <= 4; $i++) if ((int) $matches[$i] > 255) return null;
        $channels = implode(',', array_map(static fn (string $part): string => (string) (int) $part, array_slice($matches, 2, 3)));
        return $matches[1] . '(' . $channels . (isset($matches[5]) ? ',' . $matches[5] : '') . ')';
    }
}
