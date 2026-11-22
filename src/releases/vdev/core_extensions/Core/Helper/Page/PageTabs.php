<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Page;

use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\CoreExts\Core\PageComponents\Support\PageColors;

/** Validation stricte des réglages et compilation CSS limitée au composant. */
final class PageTabs
{
    public static function fields(): array
    {
        $fields = [];
        foreach (['tab', 'hover', 'active', 'body'] as $state) {
            foreach (['color', 'background_color', 'border_color'] as $field) $fields[] = $state . '_' . $field;
        }
        foreach (['tab', 'body'] as $zone) {
            foreach (['border_width', 'border_style', 'border_radius', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left'] as $field) {
                $fields[] = $zone . '_' . $field;
            }
        }
        return $fields;
    }

    public static function validate(object $props): void
    {
        if (isset($props->mode) && !in_array($props->mode, ['bootstrap', 'simple', 'custom'], true)) {
            ExceptionResponse::throwWarning('Style d’onglets invalide.');
        }
        if (isset($props->default_tab) && !is_string($props->default_tab)) ExceptionResponse::throwWarning('Onglet par défaut invalide.');
        $style = $props->tabs_style ?? new \stdClass();
        if (!is_object($style)) ExceptionResponse::throwWarning('Réglages d’onglets invalides.');
        foreach (get_object_vars($style) as $key => $value) {
            if (!in_array($key, self::fields(), true) || !is_string($value) || !self::validValue($key, $value)) {
                ExceptionResponse::throwWarning('Style d’onglets invalide : ' . $key . '.');
            }
        }
    }

    private static function validValue(string $key, string $value): bool
    {
        if ($value === '') return true;
        if (str_ends_with($key, 'color')) return PageColors::isValid($value);
        if (str_ends_with($key, 'border_style')) return in_array($value, ['none', 'solid', 'dashed', 'dotted', 'double'], true);
        return (bool) preg_match('/^(?:0|(?:0|[1-9][0-9]{0,3})(?:px|rem|em|%))$/D', $value);
    }

    public static function compile(object $document, int $pageId): string
    {
        $rules = [];
        $visit = function (array $items) use (&$visit, &$rules, $pageId): void {
            foreach ($items as $item) {
                foreach (PageDocument::childZones($item) as $zone) $visit($zone->items);
                if ($item->type === 'core.tabs') {
                    self::validate($item->props);
                    $declarations = [];
                    if (($item->props->mode ?? 'bootstrap') === 'custom') {
                        foreach (get_object_vars($item->props->tabs_style ?? new \stdClass()) as $key => $value) {
                            if ($value !== '') $declarations[] = '--pt-' . str_replace('_', '-', $key) . ':' . $value;
                        }
                    }
                    if ($declarations !== []) {
                        $rules[] = '#page-' . $pageId . ' [data-page-node="' . $item->id . '"] > .page-tabs{'
                            . implode(';', $declarations) . '}';
                    }
                }
            }
        };
        $visit($document->items);
        return implode("\n", $rules);
    }
}
