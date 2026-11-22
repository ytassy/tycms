<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;

/** Composant HTML personnalisé fourni par Core. */
final class Html extends CorePageComponent
{
    public static function getId(): string
    {
        return 'core.html';
    }

    public static function getLabel(): string
    {
        return 'HTML personnalisé';
    }

    public static function getCategory(): string
    {
        return 'Contenu';
    }

    public static function getPropsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'content' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function getDefaultData(): array
    {
        return ['content' => ''];
    }

    public static function getFormVueComponentName(): ?string
    {
        return 'core/html';
    }

    public static function getCanvasVueComponentName(): ?string
    {
        return 'core/html';
    }

    public static function getEditorFields(): array
    {
        return [['name' => 'content', 'label' => 'Contenu HTML', 'type' => 'html', 'localized' => true, 'maxLength' => 500000]];
    }

    private function shortCodes(string $html, PageRenderContext $context): string
    {
        return str_contains($html, '[[') ? $this->coreShortCodeHelper->applyShortCodes($html, $context->spaceId) : $html;
    }

    public function render(object $item, PageRenderContext $context): string
    {
        return $this->shortCodes($context->text($item->props->content ?? ''), $context);
    }
}
