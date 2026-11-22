<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageAccordion;
use YTassy\Tycms\Tools\HtmlTools;

/** Composant Accordéon fourni par Core. */
final class Accordion extends CorePageComponent
{
    public static function getId(): string
    {
        return 'core.accordion';
    }

    public static function getLabel(): string
    {
        return 'Accordéon';
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
                'mode' => ['type' => 'string'],
                'single_open' => ['type' => 'boolean'],
                'open_panels' => ['type' => 'array'],
                'show_arrow' => ['type' => 'boolean'],
                'arrow_icon' => ['type' => 'string'],
                'arrow_position' => ['type' => 'string'],
                'arrow_rotation' => ['type' => 'integer'],
                'accordion_style' => ['type' => 'object', 'additionalProperties' => true],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function getDefaultData(): array
    {
        return ['mode' => 'bootstrap', 'single_open' => true, 'open_panels' => []];
    }

    public static function getFormVueComponentName(): ?string
    {
        return 'core/accordion';
    }

    public static function getCanvasVueComponentName(): ?string
    {
        return 'core/accordion';
    }

    public static function getChildZones(): array
    {
        return ['panels' => ['label' => 'Panneau', 'formVue' => 'core/accordion', 'kind' => 'panel', 'min' => 1, 'max' => 100, 'initial' => 2, 'defaults' => ['label' => 'Panneau', 'icon' => '']]];
    }

    public static function getExclusiveReferenceFields(): array
    {
        return ['open_panels' => 'single_open'];
    }

    public static function getNodeReferenceFields(): array
    {
        return ['open_panels' => 'many'];
    }

    public function normalizeData(object $data, PageNormalizationContext $context): object
    {
        PageAccordion::validate($data);
        return clone $data;
    }

    public function validateNode(object $node, PageNormalizationContext $context): void
    {
        PageAccordion::validatePanels($node->props, $node->zones->panels);
    }

    public function render(object $item, PageRenderContext $context): string
    {
        // Comme pour Tabs, les corps refusés sont omis avant tout rendu enfant.
        $panels = array_filter($item->zones->panels, fn ($panel) => $context->isVisible($panel->props));
        if ($panels === []) return '';

        $group = 'page-' . $context->page->id . '-accordion-' . $item->id;
        $html = '<div class="page-accordion page-accordion--' . HtmlTools::escape($item->props->mode ?? 'bootstrap')
            . ' page-accordion--arrow-' . HtmlTools::escape($item->props->arrow_position ?? 'right') . '">';
        foreach ($panels as $panel) {
            $id = HtmlTools::escape($group . '-' . $panel->id);
            $name = ($item->props->single_open ?? true) ? ' name="' . HtmlTools::escape($group) . '"' : '';
            $open = in_array($panel->id, $item->props->open_panels ?? [], true) ? ' open' : '';
            $html .= '<details class="page-accordion-panel"' . $name . $open . '>';
            $html .= '<summary class="page-accordion-heading" id="' . $id . '-heading">';
            if ($panel->icon !== '') $html .= '<i class="' . HtmlTools::escape($panel->icon) . '" aria-hidden="true"></i> ';
            $html .= HtmlTools::escape($context->text($panel->label));
            if (($item->props->show_arrow ?? true) === true) {
                $icon = ($item->props->arrow_icon ?? '') ?: 'fa-solid fa-chevron-right';
                $html .= '<span class="page-accordion-arrow" aria-hidden="true"><i class="' . HtmlTools::escape($icon) . '"></i></span>';
            }
            $html .= '</summary>';
            $html .= '<div class="page-accordion-body ' . HtmlTools::escape($context->cssClasses($panel->props))
                . '" role="region" aria-labelledby="' . $id . '-heading" data-page-node="' . HtmlTools::escape($panel->id) . '">'
                . $context->renderItems($panel->items) . '</div></details>';
        }
        return $html . '</div>';
    }
}
