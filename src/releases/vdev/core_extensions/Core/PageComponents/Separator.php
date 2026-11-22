<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;

/** Composant Séparateur fourni par Core. */
final class Separator extends CorePageComponent
{
    public static function getId(): string
    {
        return 'core.hr';
    }

    public static function getLabel(): string
    {
        return 'Séparateur';
    }

    public static function getCategory(): string
    {
        return 'Structure';
    }

    public static function getDefaultData(): array
    {
        return [];
    }

    public static function getCanvasVueComponentName(): ?string
    {
        return 'core/hr';
    }

    public function render(object $item, PageRenderContext $context): string
    {
        return '<hr>';
    }
}
