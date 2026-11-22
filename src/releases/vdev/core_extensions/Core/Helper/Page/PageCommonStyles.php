<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Page;

use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\CoreExts\Core\PageComponents\Support\PageColors;

/** Compiles the validated common node styles into CSS scoped to one page. */
final class PageCommonStyles
{
    private const SIDES = ['top', 'right', 'bottom', 'left'];
    private const LENGTH = '/^(?:0|-?(?:0|[1-9][0-9]{0,3})(?:px|rem|em|%))$/';
    private const POSITIVE_LENGTH = '/^(?:0|(?:0|[1-9][0-9]{0,3})(?:px|rem|em|%))$/';
    private const PERCENT = '/^(?:100|[1-9]?[0-9])$/';
    private const IMAGE = '#^/(?!/)[a-zA-Z0-9_./%~-]+$#';

    public static function validate(object $props): void
    {
        $style = $props->common->style ?? null;
        if ($style === null) return;
        if (!is_object($style)) ExceptionResponse::throwWarning('Styles de composant invalides.');
        $valid = self::allowedFields();
        foreach (get_object_vars($style) as $key => $value) {
            if (!isset($valid[$key]) || !is_string($value) || !self::validValue($key, $value)) {
                $lengthField = $key === 'border_radius' || preg_match('/^(?:margin|padding|border|offset)_(?:top|right|bottom|left)$/', $key);
                ExceptionResponse::throwWarning('Style de composant invalide : ' . $key
                    . ($lengthField ? ' (utilisez 0 ou une longueur en px, rem, em ou %).' : '.'));
            }
        }
    }

    public static function compile(object $document, int $pageId): string
    {
        $rules = [];
        $visit = function (array $items) use (&$visit, &$rules, $pageId): void {
            foreach ($items as $item) {
                self::addRule($rules, $pageId, $item->id, $item->props);
                foreach (PageDocument::childZones($item) as $zone) {
                    self::addRule($rules, $pageId, $zone->id, $zone->props);
                    $visit($zone->items);
                }
            }
        };
        $visit($document->items);
        return implode("\n", $rules);
    }

    private static function addRule(array &$rules, int $pageId, string $id, object $props): void
    {
        self::validate($props);
        $style = $props->common->style ?? null;
        if ($style === null) return;
        $declarations = [];
        $hasBorderWidth = false;
        foreach (self::SIDES as $side) {
            if (!empty($style->{'border_' . $side})) $hasBorderWidth = true;
        }
        if ($hasBorderWidth) $declarations[] = 'border-width:0';
        foreach (self::SIDES as $side) {
            foreach (['margin' => 'margin-', 'padding' => 'padding-', 'border' => 'border-'] as $field => $css) {
                $key = $field . '_' . $side;
                if (!isset($style->{$key}) || $style->{$key} === '') continue;
                $declarations[] = $css . $side . ($field === 'border' ? '-width' : '') . ':' . $style->{$key};
            }
            $offset = 'offset_' . $side;
            if (isset($style->{$offset}) && $style->{$offset} !== '') $declarations[] = $side . ':' . $style->{$offset};
        }
        if ($hasBorderWidth || !empty($style->border_style)) $declarations[] = 'border-style:' . (($style->border_style ?? '') ?: 'solid');
        foreach (['border_color' => 'border-color', 'border_radius' => 'border-radius', 'color' => 'color',
            'link_color' => '--page-link-color', 'background_color' => 'background-color', 'text_align' => 'text-align', 'position' => 'position'] as $key => $css) {
            if (isset($style->{$key}) && $style->{$key} !== '') $declarations[] = $css . ':' . $style->{$key};
        }
        $hasImage = !empty($style->background_image);
        $imageOpacity = isset($style->background_image_opacity) && $style->background_image_opacity !== ''
            ? (int) $style->background_image_opacity : 100;
        $tintColor = (string) ($style->background_image_tint ?? '');
        $hasTint = PageColors::hasVisibleTint($tintColor);
        $layeredBackground = $hasImage && ($imageOpacity < 100 || $hasTint);
        if ($hasImage) {
            if ($layeredBackground) {
                if (empty($style->position) || $style->position === 'static') $declarations[] = 'position:relative';
                $declarations[] = 'isolation:isolate';
            } else {
                $declarations[] = 'background-image:url("' . $style->background_image . '")';
                $declarations[] = 'background-repeat:no-repeat';
                foreach (['background_position' => 'background-position', 'background_size' => 'background-size'] as $key => $css) {
                    if (!empty($style->{$key})) $declarations[] = $css . ':' . $style->{$key};
                }
            }
        }
        $selector = '#page-' . $pageId . ' [data-page-node="' . $id . '"]';
        if ($declarations !== []) $rules[] = $selector . '{' . implode(';', $declarations) . '}';

        // Hériter de la couleur la plus proche, sans recolorer les boutons.
        // La règle n'existe que lorsqu'une couleur de liens est renseignée.
        if (!empty($style->link_color)) {
            $rules[] = $selector . ' a:not(.btn):not([role="button"]){color:var(--page-link-color)}';
        }

        if ($layeredBackground) {
            $image = ['content:""', 'position:absolute', 'inset:0', 'z-index:-2', 'pointer-events:none',
                'border-radius:inherit', 'background-image:url("' . $style->background_image . '")',
                'background-repeat:no-repeat', 'opacity:' . ($imageOpacity / 100)];
            foreach (['background_position' => 'background-position', 'background_size' => 'background-size'] as $key => $css) {
                if (!empty($style->{$key})) $image[] = $css . ':' . $style->{$key};
            }
            $rules[] = $selector . '::before{' . implode(';', $image) . '}';
            if ($hasTint) {
                $rules[] = $selector . '::after{content:"";position:absolute;inset:0;z-index:-1;pointer-events:none;'
                    . 'border-radius:inherit;background-color:' . $tintColor . '}';
            }
        }
    }

    private static function allowedFields(): array
    {
        $fields = array_fill_keys(['border_style', 'border_color', 'border_radius', 'color', 'link_color', 'background_color',
            'background_image', 'background_position', 'background_size', 'background_image_opacity',
            'background_image_tint', 'text_align', 'position'], true);
        foreach (self::SIDES as $side) {
            foreach (['margin', 'padding', 'border', 'offset'] as $prefix) $fields[$prefix . '_' . $side] = true;
        }
        return $fields;
    }

    private static function validValue(string $key, string $value): bool
    {
        if ($value === '') return true;
        if (str_starts_with($key, 'margin_') || str_starts_with($key, 'offset_')) return (bool) preg_match(self::LENGTH, $value);
        if (str_starts_with($key, 'padding_') || $key === 'border_radius'
            || in_array($key, ['border_top', 'border_right', 'border_bottom', 'border_left'], true)) {
            return (bool) preg_match(self::POSITIVE_LENGTH, $value);
        }
        if (in_array($key, ['border_color', 'color', 'link_color', 'background_color'], true)) return PageColors::isValid($value);
        if ($key === 'background_image') return (bool) preg_match(self::IMAGE, $value) && !str_contains($value, '..');
        if ($key === 'background_image_tint') return PageColors::isValid($value, false);
        if ($key === 'background_image_opacity') return (bool) preg_match(self::PERCENT, $value);
        if ($key === 'text_align') return in_array($value, ['left', 'center', 'right'], true);
        return match ($key) {
            'border_style' => in_array($value, ['solid', 'dashed', 'dotted', 'double'], true),
            'background_position' => in_array($value, ['center', 'top', 'bottom', 'left', 'right'], true)
                || (bool) preg_match('/^(?:100|[1-9]?[0-9])% (?:100|[1-9]?[0-9])%$/', $value),
            'background_size' => in_array($value, ['auto', 'cover', 'contain'], true),
            'position' => in_array($value, ['static', 'relative', 'absolute', 'sticky'], true),
            default => false,
        };
    }
}
