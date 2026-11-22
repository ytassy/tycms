<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageEditContext;
use YTassy\Tycms\Lib\Forms\Form;

/** Composant Menu fourni par Core. */
final class Menu extends CorePageComponent
{
    public static function getId(): string
    {
        return 'core.menu';
    }

    public static function getLabel(): string
    {
        return 'Menu';
    }

    public static function getCategory(): string
    {
        return 'Navigation';
    }

    public static function getPropsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'menu_id' => ['type' => 'integer'],
                'display_type' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function getDefaultData(): array
    {
        return ['menu_id' => 0, 'display_type' => 'simple'];
    }

    public function getEditContext(PageEditContext $context): array
    {
        $menus = [];
        foreach ($this->coreSpaceHelper->getSpaceIdsWithParents($context->spaceId) as $spaceId) {
            foreach ($this->coreMenuHelper->getMenusAnItemsOfSpace($spaceId) as $menu) {
                $menus[] = ['value' => $menu->id, 'label' => $menu->name];
            }
        }
        return ['plg_core_page_menus' => $menus];
    }

    public function getGeneralForm(PageEditContext $context): ?Form
    {
        return (new Form($this))->setId('core.page.menu')->setHideSingleSectionTab()
            ->addSection('general', 'Général')
            ->addField('general', 'menu_id', 'core.select', ['label' => 'Menu', 'default' => 0,
                'options' => array_merge([['value' => 0, 'label' => 'Choisir un menu']], $context->get('plg_core_page_menus', []))])
            ->addField('general', 'display_type', 'core.select', ['label' => 'Affichage', 'default' => 'simple', 'options' => ['simple' => 'Simple', 'main' => 'Principal']]);
    }

    public function validateData(object $data, PageNormalizationContext $context): void
    {
        if (($data->menu_id ?? 0) === 0) return;
        $menu = $this->coreMenuAdminHelper->getMenu((int) $data->menu_id);
        $this->assert($menu !== null
            && in_array($menu->space_id, $this->coreSpaceHelper->getSpaceIdsWithParents($context->spaceId), true),
            'Menu de page invalide.');
    }

    public function render(object $item, PageRenderContext $context): string
    {
        $props = $item->props;
        $id = (int) ($props->menu_id ?? 0);
        if ($id < 1) return '';
        $display = (string) ($props->display_type ?? 'simple');
        if (!in_array($display, ['simple', 'main'], true)) $display = 'simple';
        return $this->coreMenuHelper->getHtml($id, $display, true);
    }
}
