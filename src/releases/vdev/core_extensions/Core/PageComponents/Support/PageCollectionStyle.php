<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents\Support;

use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tools\HtmlTools;

/** Styles bornés des cartes répétées : aucune déclaration CSS libre. */
final class PageCollectionStyle
{
    public const COLORS = ['background_color', 'color', 'border_color', 'accent_color', 'author_color', 'muted_color', 'line_color', 'marker_color', 'label_color'];
    public const SIZES = ['gap' => [0, 160], 'padding' => [0, 120], 'radius' => [0, 100], 'border_width' => [0, 12],
        'portrait_size' => [24, 240], 'text_size' => [10, 48], 'author_size' => [10, 48], 'line_width' => [1, 12],
        'marker_size' => [8, 64], 'label_size' => [10, 48]];

    public static function validate(mixed $style, array $fields): void
    {
        if (!is_object($style)) ExceptionResponse::throwWarning('Styles de cartes invalides.');

        foreach (get_object_vars($style) as $key => $value) {
            $valid = in_array($key, $fields, true) && match (true) {
                in_array($key, self::COLORS, true) => is_string($value) && PageColors::isValid($value),
                isset(self::SIZES[$key]) => is_int($value) && $value >= self::SIZES[$key][0] && $value <= self::SIZES[$key][1],
                $key === 'shadow' => in_array($value, ['none', 'soft', 'medium'], true),
                default => false,
            };
            if (!$valid) ExceptionResponse::throwWarning('Style de cartes invalide : ' . $key . '.');
        }
    }

    public static function variables(object $style, array $fields): string
    {
        self::validate($style, $fields);
        $css = '';
        foreach (get_object_vars($style) as $key => $value) {
            $value = match (true) {
                isset(self::SIZES[$key]) => $value . 'px',
                $key === 'shadow' => match ($value) {
                    'soft' => '0 4px 18px #0000000D', 'medium' => '0 8px 28px #0000001F', default => 'none',
                },
                default => $value,
            };
            $css .= '--pc-' . str_replace('_', '-', $key) . ':' . $value . ';';
        }
        return HtmlTools::escape($css);
    }
}
