<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents\Support;

use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Lib\HtmlSanitizer\CssColorVariable;
use YTassy\Tycms\Response\ExceptionResponse;

/** Valeurs de couleur des composants : hexadécimal ou référence CSS simple et déclarée. */
final class PageColors
{
    private const VARIABLE_NAME = '/^--[a-zA-Z_][a-zA-Z0-9_-]{0,63}$/D';

    public static function variableName(string $value): ?string
    {
        return CssColorVariable::name($value);
    }

    public static function isValid(string $value, bool $shortHex = true): bool
    {
        return self::variableName($value) !== null
            || (bool) preg_match($shortHex ? '/^#(?:[a-fA-F0-9]{3}|[a-fA-F0-9]{6}|[a-fA-F0-9]{8})$/D'
                : '/^#[a-fA-F0-9]{6}(?:[a-fA-F0-9]{2})?$/D', $value);
    }

    public static function hasVisibleTint(string $value): bool
    {
        return self::variableName($value) !== null
            || (self::isValid($value, false) && (strlen($value) === 7 || hexdec(substr($value, 7, 2)) > 0));
    }

    /** Le catalogue est déclaré en code ; seules ces deux métadonnées sont exposées au navigateur. */
    public static function normalizeCatalogue(array $entries): array
    {
        if (!array_is_list($entries) || count($entries) > 128) throw new TechException('Catalogue de couleurs de template invalide.');
        $result = [];
        $seen = [];
        foreach ($entries as $entry) {
            $variable = is_array($entry) ? ($entry['variable'] ?? null) : null;
            $label = is_array($entry) ? ($entry['label'] ?? null) : null;
            if (!is_string($variable) || !preg_match(self::VARIABLE_NAME, $variable) || isset($seen[$variable])
                || !is_string($label) || trim($label) === '' || !mb_check_encoding($label, 'UTF-8') || mb_strlen($label) > 100) {
                throw new TechException('Déclaration de couleur de template invalide ou répétée.');
            }
            $seen[$variable] = true;
            $result[] = ['variable' => $variable, 'label' => $label];
        }
        return $result;
    }

    /** Contrôle à l’entrée seulement : une ancienne référence reste lisible après changement de template. */
    public static function validateDocumentReferences(object $document, array $catalogue): void
    {
        $allowed = array_fill_keys(array_column($catalogue, 'variable'), true);
        $check = static function (object $props, bool $component) use ($allowed): void {
            $styles = [$props->common->style ?? null];
            if ($component) {
                foreach (['button_style', 'tabs_style', 'accordion_style', 'audio_style', 'testimonials_style', 'timeline_style'] as $field) $styles[] = $props->{$field} ?? null;
            }
            foreach ($styles as $style) {
                if (!is_object($style)) continue;
                foreach (get_object_vars($style) as $value) {
                    if (!is_string($value)) continue;
                    $name = self::variableName($value);
                    if ($name !== null && !isset($allowed[$name])) {
                        ExceptionResponse::throwWarning('La couleur ' . $name . ' n’est pas disponible dans la template de ce Space. Choisissez une autre couleur.');
                    }
                }
            }
        };
        $visit = static function (array $items) use (&$visit, $check): void {
            foreach ($items as $item) {
                $check($item->props, in_array($item->type, ['core.button', 'core.tabs', 'core.accordion', 'core.audio', 'core.testimonials', 'core.timeline'], true));
                foreach (PageDocument::childZones($item) as $zone) {
                    $check($zone->props, false);
                    $visit($zone->items);
                }
            }
        };
        $visit($document->items);
    }
}
