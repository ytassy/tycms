<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\CoreExts\Core\PageComponents\Support\PageCollectionStyle;

/** Frise verticale dont chaque étape accueille les composants de l'éditeur. */
final class Timeline extends CorePageComponent
{
    public const STYLE_FIELDS = ['background_color', 'color', 'border_color', 'line_color', 'marker_color', 'label_color',
        'gap', 'padding', 'radius', 'border_width', 'line_width', 'marker_size', 'label_size', 'shadow'];

    public static function getId(): string { return 'core.timeline'; }
    public static function getLabel(): string { return 'Timeline (chronologie)'; }
    public static function getCategory(): string { return 'Structure'; }
    public static function getFormVueComponentName(): ?string { return 'core/timeline'; }
    public static function getCanvasVueComponentName(): ?string { return 'core/timeline'; }

    public static function getPropsSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'layout' => ['type' => 'string', 'enum' => ['alternate', 'left', 'right']],
            'label_position' => ['type' => 'string', 'enum' => ['above', 'opposite']],
            'mobile_breakpoint' => ['type' => 'string', 'enum' => ['sm', 'md', 'lg']],
            'marker' => ['type' => 'string', 'enum' => ['dot', 'number', 'icon']],
            'line_style' => ['type' => 'string', 'enum' => ['solid', 'dashed', 'dotted']],
            'show_pointer' => ['type' => 'boolean'],
            'timeline_style' => ['type' => 'object', 'additionalProperties' => true],
        ], 'additionalProperties' => false];
    }

    public static function getDefaultData(): array
    {
        return ['layout' => 'alternate', 'label_position' => 'above', 'mobile_breakpoint' => 'md', 'marker' => 'dot', 'line_style' => 'solid',
            'show_pointer' => true, 'timeline_style' => (object) []];
    }

    public static function getChildZones(): array
    {
        return ['steps' => ['label' => 'Étape', 'formVue' => 'core/timeline', 'kind' => 'zone', 'min' => 1, 'max' => 100,
            'initial' => 3, 'defaults' => ['label' => 'Étape', 'icon' => '']]];
    }

    public function normalizeData(object $data, PageNormalizationContext $context): object
    {
        PageCollectionStyle::validate($data->timeline_style ?? new \stdClass(), self::STYLE_FIELDS);
        return clone $data;
    }

    public function render(object $item, PageRenderContext $context): string
    {
        $props = $item->props;
        $steps = array_values(array_filter($item->zones->steps, fn ($step) => $context->isVisible($step->props)));
        if ($steps === []) return '';

        $classes = 'page-timeline page-timeline--' . $props->layout . ' page-timeline--break-' . $props->mobile_breakpoint
            . (($props->label_position ?? 'above') === 'opposite' ? ' page-timeline--labels-opposite' : '')
            . ($props->show_pointer ? ' page-timeline--pointer' : '');
        $html = '<ol class="' . $this->escape($classes) . '" style="'
            . PageCollectionStyle::variables($props->timeline_style, self::STYLE_FIELDS)
            . '--pc-line-style:' . $this->escape($props->line_style) . '">';

        // L'ordre et la numérotation suivent uniquement les étapes réellement visibles.
        foreach ($steps as $index => $step) {
            $marker = match ($props->marker) {
                'number' => (string) ($index + 1),
                'icon' => '<i class="' . $this->escape($step->icon ?: 'fa-solid fa-circle') . '"></i>',
                default => '',
            };
            $html .= '<li class="page-timeline-step"><span class="page-timeline-marker" aria-hidden="true">' . $marker . '</span>'
                . '<div class="page-timeline-content"><div class="page-timeline-label">' . $this->escape($context->text($step->label)) . '</div>'
                . '<div class="page-timeline-card">' . $context->renderZone($step) . '</div></div></li>';
        }
        return $html . '</ol>';
    }
}
