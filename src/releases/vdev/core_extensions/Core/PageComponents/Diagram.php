<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageEditContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageSize;
use YTassy\Tycms\CoreExts\Core\PageComponents\Support\DrawioSvg;

/** Diagramme autonome : le SVG et sa source restent dans le JSON de la page. */
final class Diagram extends CorePageComponent
{
    public static function getId(): string { return 'core.diagram'; }
    public static function getLabel(): string { return 'Diagramme diagrams.net'; }
    public static function getCategory(): string { return 'Contenu'; }
    public static function getDefaultData(): array { return ['svg' => '', 'alt' => '']; }
    public static function getFormVueComponentName(): ?string { return 'core/diagram'; }
    public static function getCanvasVueComponentName(): ?string { return 'core/diagram'; }

    public static function getPropsSchema(): array
    {
        return ['type' => 'object', 'properties' => ['svg' => ['type' => 'string'], 'alt' => ['type' => 'string']],
            'additionalProperties' => false];
    }

    public static function getEditorFields(): array
    {
        return [['name' => 'alt', 'label' => 'Texte alternatif', 'type' => 'text', 'localized' => true]];
    }

    public function getEditContext(PageEditContext $context): array
    {
        return ['plg_core_diagram_max_size' => PageSize::diagramMaximum($this->app->getConf())];
    }

    public function normalizeData(object $data, PageNormalizationContext $context): object
    {
        $this->assert(is_string($data->svg ?? null), 'Source SVG du diagramme invalide.');
        if ($context->isInput()) DrawioSvg::validate($data->svg, PageSize::diagramMaximum($this->app->getConf()));
        return clone $data;
    }

    public function render(object $node, PageRenderContext $context): string
    {
        if (($node->props->svg ?? '') === '') return '';

        // Un document image data: ne devient jamais du HTML actif dans l'origine du site.
        return '<img class="img-fluid" src="data:image/svg+xml;base64,' . base64_encode($node->props->svg)
            . '" alt="' . $this->escape($context->text($node->props->alt ?? '')) . '" loading="lazy">';
    }
}
