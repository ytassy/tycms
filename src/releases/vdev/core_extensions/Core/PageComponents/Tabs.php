<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageTabs;
use YTassy\Tycms\Tools\HtmlTools;
use YTassy\Tycms\Response\ExceptionResponse;

/** Composant Tabs (onglets) fourni par Core. */
final class Tabs extends CorePageComponent
{
    public static function getId(): string
    {
        return 'core.tabs';
    }

    public static function getLabel(): string
    {
        return 'Tabs (onglets)';
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
                'default_tab' => ['type' => 'string'],
                'tabs_style' => ['type' => 'object', 'additionalProperties' => true],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function getDefaultData(): array
    {
        return ['mode' => 'bootstrap'];
    }

    public static function getFormVueComponentName(): ?string
    {
        return 'core/tabs';
    }

    public static function getCanvasVueComponentName(): ?string
    {
        return 'core/tabs';
    }

    public static function getChildZones(): array
    {
        return ['tabs' => ['label' => 'Onglet', 'formVue' => 'core/tabs', 'kind' => 'tab', 'min' => 1, 'max' => 100, 'initial' => 2, 'defaults' => ['label' => 'Onglet', 'icon' => '']]];
    }

    public static function getNodeReferenceFields(): array
    {
        return ['default_tab' => 'one'];
    }

    public function normalizeData(object $data, PageNormalizationContext $context): object
    {
        PageTabs::validate($data);
        return clone $data;
    }

    public function validateNode(object $node, PageNormalizationContext $context): void
    {
        if (isset($node->props->default_tab) && !in_array($node->props->default_tab, array_column($node->zones->tabs, 'id'), true)) ExceptionResponse::throwWarning('Onglet ouvert par défaut invalide.');
    }

    public function render(object $item, PageRenderContext $context): string
    {
        // Ne jamais transmettre au navigateur le contenu d’un corps non autorisé.
        $tabs = array_values(array_filter($item->zones->tabs, fn ($tab) => $context->isVisible($tab->props)));
        if ($tabs === []) return '';
        $default = $item->props->default_tab ?? '';
        if (!in_array($default, array_column($tabs, 'id'), true)) $default = $tabs[0]->id;
        $group = 'page-' . $context->page->id . '-tabs-' . $item->id;
        $html = '<div class="page-tabs page-tabs--' . HtmlTools::escape($item->props->mode ?? 'bootstrap') . '" role="group" aria-label="Onglets">';
        foreach ($tabs as $tab) {
            $id = HtmlTools::escape($group . '-' . $tab->id);
            $html .= '<input class="page-tabs-radio visually-hidden" type="radio" name="' . HtmlTools::escape($group)
                . '" id="' . $id . '" aria-controls="' . $id . '-panel"' . ($tab->id === $default ? ' checked' : '') . '>';
            $html .= '<label class="page-tabs-label" id="' . $id . '-label" for="' . $id . '">';
            if ($tab->icon !== '') $html .= '<i class="' . HtmlTools::escape($tab->icon) . '" aria-hidden="true"></i> ';
            $html .= HtmlTools::escape($context->text($tab->label)) . '</label>';
            $html .= '<section class="page-tabs-panel ' . HtmlTools::escape($context->cssClasses($tab->props))
                . '" id="' . $id . '-panel" role="region" aria-labelledby="' . $id . '-label" data-page-node="'
                . HtmlTools::escape($tab->id) . '">' . $context->renderItems($tab->items) . '</section>';
        }
        return $html . '</div>';
    }
}
