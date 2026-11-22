<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\CoreExts\Core\PageComponents\Support\PageLinks;
use YTassy\Tycms\Tools\HtmlTools;

/** Composant Image fourni par Core. */
final class Image extends CorePageComponent
{
    public static function getId(): string
    {
        return 'core.image';
    }

    public static function getLabel(): string
    {
        return 'Image';
    }

    public static function getCategory(): string
    {
        return 'Médias';
    }

    public static function getPropsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'src' => ['type' => 'string'],
                'alt' => ['type' => 'string'],
                'click_action' => ['type' => 'string'],
                'width' => ['type' => 'string'],
                'max_width' => ['type' => 'string'],
                'max_height' => ['type' => 'string'],
                'href' => ['type' => 'string'],
                'link_type' => ['type' => 'string'],
                'menu_item_id' => ['type' => 'integer'],
                'link_new_tab' => ['type' => 'boolean'],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function getDefaultData(): array
    {
        return ['src' => '', 'alt' => '', 'click_action' => 'none'];
    }

    public static function getFormVueComponentName(): ?string
    {
        return 'core/image';
    }

    public static function getCanvasVueComponentName(): ?string
    {
        return 'core/image';
    }

    public static function getEditorFields(): array
    {
        return [['name' => 'alt', 'label' => 'Texte alternatif', 'type' => 'text', 'localized' => true]];
    }

    public function normalizeData(object $data, PageNormalizationContext $context): object
    {
        $this->validateLink($data, $context, true);
        $this->assert(is_string($data->src ?? null), 'Source d’image invalide.');
        return clone $data;
    }

    public function render(object $item, PageRenderContext $context): string
    {
        $props = $item->props;
        $src = $this->siteUrl((string) ($props->src ?? ''));
        if ($src === null) return '';
        $style = '';
        $imageStyle = '';
        $action = $props->click_action ?? 'none';
        $enlarge = $action === 'enlarge';
        $href = $action === 'link' ? $this->linkHref($context, $props) : null;
        foreach (['width' => 'width', 'max_width' => 'max-width', 'max_height' => 'max-height'] as $field => $cssName) {
            $value = (string) ($props->{$field} ?? '');
            if (preg_match('/^(?:[1-9][0-9]{0,3})(?:px|%)$/', $value)) {
                $style .= $cssName . ':' . $value . ';';
                if ($field === 'max_height') $imageStyle .= $cssName . ':' . $value . ';';
            }
        }
        // Les pourcentages appartiennent au bouton pour ne pas se multiplier sur son image enfant.
        $imageStyle = ($enlarge || $href !== null) ? 'width:100%;' . $imageStyle : $style;
        $image = '<img class="img-fluid" src="' . HtmlTools::escape($src) . '" alt="'
            . HtmlTools::escape($context->text($props->alt ?? '')) . '" style="' . $imageStyle . '" loading="lazy">';

        // Sans script, l’image reste lisible ; ne jamais naviguer directement vers un SVG potentiellement actif.
        if ($href !== null) {
            return '<a class="page-image-link" href="' . HtmlTools::escape($href) . '"'
                . PageLinks::targetAttributes($props) . ' style="display:inline-block;max-width:100%;' . $style . '">' . $image . '</a>';
        }
        if (!$enlarge) return $image;
        return '<button type="button" class="page-image-enlarge" data-page-image-lightbox data-page-image-src="' . HtmlTools::escape($src)
            . '" style="' . $style . '" aria-label="Agrandir l’image" aria-haspopup="dialog">' . $image . '</button>';
    }
}
