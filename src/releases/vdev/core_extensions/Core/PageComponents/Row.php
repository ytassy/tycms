<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\Tools\HtmlTools;

/** Composant Ligne fourni par Core. */
final class Row extends CorePageComponent
{
    public static function getId(): string
    {
        return 'core.row';
    }

    public static function getLabel(): string
    {
        return 'Ligne';
    }

    public static function getCategory(): string
    {
        return 'Structure';
    }

    public static function getPropsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
            ],
            'additionalProperties' => false,
        ];
    }

    public static function getDefaultData(): array
    {
        return [];
    }

    public static function getFormVueComponentName(): ?string
    {
        return 'core/row';
    }

    public static function getCanvasVueComponentName(): ?string
    {
        return 'core/row';
    }

    public static function getChildZones(): array
    {
        return ['columns' => ['label' => 'Colonne', 'formVue' => 'core/column', 'kind' => 'column', 'min' => 1, 'max' => 100, 'initial' => 1, 'defaults' => ['sizes' => (object) ['xs' => 12]]]];
    }

    public function normalizeZone(string $name, object $zone, PageNormalizationContext $context): object
    {
        if (get_object_vars($zone->sizes) === []) $zone->sizes = (object) ['xs' => 12];
        return $zone;
    }

    public function render(object $item, PageRenderContext $context): string
    {
        $html = '<div class="row">';
        foreach ($item->zones->columns as $column) {
            if (!$context->isVisible($column->props)) continue;
            $classes = [];
            foreach (get_object_vars($column->sizes) as $breakpoint => $size) {
                if (!in_array($breakpoint, ['xs', 'sm', 'md', 'lg', 'xl', 'xxl'], true)) continue;
                if (!is_int($size) || $size < 1 || $size > 12) continue;
                $classes[] = $breakpoint === 'xs' ? 'col-' . $size : 'col-' . $breakpoint . '-' . $size;
            }
            $align = $column->props->align ?? null;
            if (in_array($align, ['left', 'center', 'right'], true)
                && empty($column->props->common->style->text_align)) {
                $classes[] = match ($align) { 'left' => 'text-start', 'center' => 'text-center', 'right' => 'text-end' };
            }
            $html .= '<div class="' . implode(' ', $classes) . '" data-page-node="'
                . HtmlTools::escape($column->id) . '">'
                . $context->renderItems($column->items) . '</div>';
        }
        return $html . '</div>';
    }
}
