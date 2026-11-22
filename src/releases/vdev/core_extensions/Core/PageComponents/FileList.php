<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageFileList;

/** Composant Liste de fichiers fourni par Core. */
final class FileList extends CorePageComponent
{
    public static function getId(): string
    {
        return 'core.file-list';
    }

    public static function getLabel(): string
    {
        return 'Liste de fichiers';
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
                'folder' => ['type' => ['string', 'null']],
                'compact' => ['type' => 'boolean'],
                'show_date' => ['type' => 'boolean'],
                'show_size' => ['type' => 'boolean'],
                'show_thumbnails' => ['type' => 'boolean'],
                'show_open' => ['type' => 'boolean'],
                'show_download' => ['type' => 'boolean'],
                'sort_1' => ['type' => 'string'],
                'direction_1' => ['type' => 'string'],
                'first_1' => ['type' => 'string'],
                'sort_2' => ['type' => 'string'],
                'direction_2' => ['type' => 'string'],
                'first_2' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function getDefaultData(): array
    {
        return ['folder' => null, 'compact' => false, 'show_date' => true, 'show_size' => true, 'show_thumbnails' => false, 'show_open' => true, 'show_download' => true, 'sort_1' => 'name', 'direction_1' => 'asc', 'first_1' => '', 'sort_2' => 'name', 'direction_2' => 'asc', 'first_2' => ''];
    }

    public static function getFormVueComponentName(): ?string
    {
        return 'core/file-list';
    }

    public static function getCanvasVueComponentName(): ?string
    {
        return 'core/file-list';
    }

    public function normalizeData(object $data, PageNormalizationContext $context): object
    {
        PageFileList::validate($data);
        return clone $data;
    }

    public function validateData(object $data, PageNormalizationContext $context): void
    {
        $this->corePageFileList->validateForEditor($data, $context->spaceId);
    }

    public function render(object $item, PageRenderContext $context): string
    {
        return $this->corePageFileList->render($context->spaceId, $item->props, $context->locale);
    }
}
