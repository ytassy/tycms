<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Page;

use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\CoreExts\Core\PageComponents\Support\PageColors;

/** Validates button options and compiles their Bootstrap variable overrides. */
final class PageButtonStyles
{
    private const VARIANTS = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark',
        'link', 'outline-primary', 'outline-secondary', 'outline-success', 'outline-danger',
        'outline-warning', 'outline-info', 'outline-light', 'outline-dark'];
    private const VARIABLES = [
        'color' => '--bs-btn-color', 'background_color' => '--bs-btn-bg', 'border_color' => '--bs-btn-border-color',
        'hover_color' => '--bs-btn-hover-color', 'hover_background_color' => '--bs-btn-hover-bg',
        'hover_border_color' => '--bs-btn-hover-border-color', 'border_width' => '--bs-btn-border-width',
        'border_radius' => '--bs-btn-border-radius',
    ];
    private const WIDTH = '/^(?:0|(?:0|[1-9][0-9]{0,3})(?:px|rem|em))$/';
    private const RADIUS = '/^(?:0|(?:0|[1-9][0-9]{0,3})(?:px|rem|em|%))$/';

    public static function validate(object $props): void
    {
        if (isset($props->variant) && (!is_string($props->variant) || !in_array($props->variant, self::VARIANTS, true))) {
            ExceptionResponse::throwWarning('Variante de bouton invalide.');
        }
        if (isset($props->size) && (!is_string($props->size) || !in_array($props->size, ['', 'sm', 'lg'], true))) {
            ExceptionResponse::throwWarning('Taille de bouton invalide.');
        }
        $style = $props->button_style ?? null;
        if ($style === null) return;
        if (!is_object($style)) ExceptionResponse::throwWarning('Style de bouton invalide.');
        foreach (get_object_vars($style) as $key => $value) {
            if (!isset(self::VARIABLES[$key]) || !is_string($value) || !self::validValue($key, $value)) {
                ExceptionResponse::throwWarning('Style de bouton invalide : ' . $key . '.');
            }
        }
    }

    public static function classes(object $props): string
    {
        $variant = $props->variant ?? null;
        if (!is_string($variant) || !in_array($variant, self::VARIANTS, true)) $variant = 'primary';
        $classes = 'btn btn-' . $variant;
        if (in_array($props->size ?? null, ['sm', 'lg'], true)) $classes .= ' btn-' . $props->size;
        return $classes;
    }

    public static function compile(object $document, int $pageId): string
    {
        $rules = [];
        $visit = function (array $items) use (&$visit, &$rules, $pageId): void {
            foreach ($items as $item) {
                foreach (PageDocument::childZones($item) as $zone) $visit($zone->items);
                if ($item->type !== 'core.button') continue;
                self::validate($item->props);
                $declarations = [];
                foreach (get_object_vars($item->props->button_style ?? new \stdClass()) as $key => $value) {
                    if ($value !== '') $declarations[] = self::VARIABLES[$key] . ':' . $value;
                }
                if ($declarations !== []) {
                    $rules[] = '#page-' . $pageId . ' [data-page-node="' . $item->id . '"] .btn{'
                        . implode(';', $declarations) . '}';
                }
            }
        };
        $visit($document->items);
        return implode("\n", $rules);
    }

    private static function validValue(string $key, string $value): bool
    {
        if ($value === '') return true;
        if (!in_array($key, ['border_width', 'border_radius'], true)) return PageColors::isValid($value);
        return (bool) preg_match($key === 'border_width' ? self::WIDTH : self::RADIUS, $value);
    }
}
