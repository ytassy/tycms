<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageEditContext;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\Tools\HtmlTools;

/** Composant Titre fourni par Core. */
final class Heading extends CorePageComponent
{
    public static function getId(): string
    {
        return 'core.heading';
    }

    public static function getLabel(): string
    {
        return 'Titre';
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
                'level' => ['type' => 'integer'],
                'text' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function getDefaultData(): array
    {
        return ['level' => 2, 'text' => ''];
    }

    public static function getCanvasVueComponentName(): ?string
    {
        return 'core/heading';
    }

    public static function getEditorFields(): array
    {
        return [['name' => 'text', 'label' => 'Titre', 'type' => 'text', 'localized' => true]];
    }

    public function normalizeData(object $data, PageNormalizationContext $context): object
    {
        $this->assert(is_int($data->level ?? null) && in_array($data->level, [1, 2, 3, 4], true), 'Niveau de titre invalide.');
        return clone $data;
    }

    public function getGeneralForm(PageEditContext $context): ?Form
    {
        return (new Form($this))->setId('core.page.heading')->setHideSingleSectionTab()
            ->addSection('general', 'Général')
            ->addField('general', 'level', 'core.select', ['label' => 'Niveau', 'default' => 2, 'options' => [
                ['value' => 1, 'label' => 'H1'], ['value' => 2, 'label' => 'H2'], ['value' => 3, 'label' => 'H3'], ['value' => 4, 'label' => 'H4'],
            ]])
            ->addField('general', 'text', 'core.localized_text', ['label' => 'Titre', 'default' => '']);
    }

    public function render(object $item, PageRenderContext $context): string
    {
        $props = $item->props;
        $level = in_array((int) ($props->level ?? 2), [1, 2, 3, 4], true) ? (int) $props->level : 2;
        return '<h' . $level . '>' . HtmlTools::escape($context->text($props->text ?? '')) . '</h' . $level . '>';
    }
}
