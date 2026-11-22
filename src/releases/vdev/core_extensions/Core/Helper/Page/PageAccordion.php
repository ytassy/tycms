<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Page;

use YTassy\Tycms\Response\ExceptionResponse;

/** Contrat des panneaux et réutilisation des styles structurés des conteneurs. */
final class PageAccordion
{
    public static function validate(object $props): void
    {
        $styles = (object) ['mode' => $props->mode ?? 'bootstrap', 'tabs_style' => $props->accordion_style ?? new \stdClass()];
        PageTabs::validate($styles);

        if (property_exists($props, 'single_open') && !is_bool($props->single_open)) {
            ExceptionResponse::throwWarning('L’option « Un seul panneau ouvert à la fois » doit être un booléen.');
        }
        if (property_exists($props, 'show_arrow') && !is_bool($props->show_arrow)) {
            ExceptionResponse::throwWarning('L’option d’affichage de la flèche doit être un booléen.');
        }
        if (isset($props->arrow_position) && !in_array($props->arrow_position, ['left', 'right'], true)) {
            ExceptionResponse::throwWarning('Position de flèche invalide.');
        }
        if (property_exists($props, 'arrow_rotation') && (!is_int($props->arrow_rotation)
            || $props->arrow_rotation < -360 || $props->arrow_rotation > 360)) {
            ExceptionResponse::throwWarning('La rotation de la flèche doit être un entier entre −360 et 360 degrés.');
        }
        $icon = $props->arrow_icon ?? '';
        if (!is_string($icon) || ($icon !== '' && !preg_match('/^fa-(?:solid|regular|brands) fa-[a-z0-9-]{1,80}$/D', $icon))) {
            ExceptionResponse::throwWarning('Icône de flèche invalide.');
        }
        $open = $props->open_panels ?? [];
        if (!is_array($open) || !array_is_list($open) || count($open) > 12) {
            ExceptionResponse::throwWarning('Panneaux ouverts invalides.');
        }
        $seen = [];
        foreach ($open as $id) {
            if (!is_string($id) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $id) || isset($seen[$id])) {
                ExceptionResponse::throwWarning('Panneau ouvert invalide ou répété.');
            }
            $seen[$id] = true;
        }
        if (($props->single_open ?? true) && count($open) > 1) {
            ExceptionResponse::throwWarning('Un seul panneau peut être ouvert au départ dans ce mode.');
        }
    }

    public static function validatePanels(object $props, array $panels): void
    {
        foreach ($props->open_panels ?? [] as $id) {
            if (!in_array($id, array_column($panels, 'id'), true)) ExceptionResponse::throwWarning('Panneau ouvert introuvable.');
        }
    }

    public static function compile(object $document, int $pageId): string
    {
        $rules = [];
        $visit = function (array $items) use (&$visit, &$rules, $pageId): void {
            foreach ($items as $item) {
                foreach (PageDocument::childZones($item) as $zone) $visit($zone->items);
                if ($item->type !== 'core.accordion') continue;
                self::validate($item->props);

                // Les champs de styles des en-têtes partagent le contrat de Tabs.
                $declarations = [];
                if (isset($item->props->arrow_rotation)) {
                    $declarations[] = '--page-accordion-arrow-rotation:' . $item->props->arrow_rotation . 'deg';
                }
                if (($item->props->mode ?? 'bootstrap') === 'custom') {
                    foreach (get_object_vars($item->props->accordion_style ?? new \stdClass()) as $key => $value) {
                        if ($value !== '') $declarations[] = '--pt-' . str_replace('_', '-', $key) . ':' . $value;
                    }
                }
                if ($declarations !== []) {
                    $rules[] = '#page-' . $pageId . ' [data-page-node="' . $item->id . '"] > .page-accordion{'
                        . implode(';', $declarations) . '}';
                }
            }
        };
        $visit($document->items);
        return implode("\n", $rules);
    }
}
