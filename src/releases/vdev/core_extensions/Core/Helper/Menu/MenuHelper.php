<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Menu;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Application;
use Override;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Models\Menu;
use YTassy\Tycms\CoreExts\Core\Models\MenuItem;
use YTassy\Tycms\Routing\Router;
use YTassy\Tycms\Tools\ArrayTools;
use YTassy\Tycms\Tools\HtmlTools;
use YTassy\Tycms\WF\BaseHelper;

class MenuHelper extends BaseHelper
{
    use TraitAllHelpers;

    private array $menusBySpaceIdAndId = [];
    private array $menusById = [];

    public function __construct(BaseApplicationItem|Application $app)
    {
        parent::__construct($app);

        $this->load();
    }

    private function load(){
        $allMenus = $this->db->selectOrm(Menu::class, 'SELECT * FROM menus');

        $allItems = ArrayTools::indexArray(
            $this->db->selectOrm(MenuItem::class, 'SELECT * FROM menu_items WHERE status >= 1'),
            'menu_id', true);

        foreach($allMenus as $menu) $menu->items = $allItems[$menu->id] ?? [];

        $this->menusBySpaceIdAndId = [];
        $this->menusById = [];

        foreach($allMenus as $menu) {
            $this->menusBySpaceIdAndId[$menu->space_id][$menu->id] = $menu;
            $this->menusById[$menu->id] = $menu;
        }
    }

    /**
     * @var ?int $spaceId. If null get the current space
     * @return array<Menu>
     */
    public function getMenusAnItemsOfSpace(?int $spaceId = null): array
    {
        if ($spaceId === null) $spaceId = $this->coreSpaceHelper->getCurrentSpace()->id;

        return $this->menusBySpaceIdAndId[$spaceId] ?? [];
    }


    /**
     * 
     * @return array{0: Menu|null, 1: MenuItem|null} 
     */
    public function getMenuItemAndMenu(int $menuItemId): array
    {
        foreach($this->menusBySpaceIdAndId as $menus)
        {
            foreach($menus as $m) {
                foreach($m->items as $mi) {
                    if ($mi->id == $menuItemId) return [$m, $mi];
                }
            }
        }

        return [null, null];
    }

    public function getRouteNameFromMenuItem(int $menuItemId, string $method = 'GET'): ?string{
        return $this->coreMenuRouteHelper->getRouteNameFromMenuItem($menuItemId, $method);
    }

    public function getUrlFromMenuItem(int $menuItemId, string $method = 'GET'): ?string{
        return $this->coreMenuRouteHelper->getUrlFromMenuItem($menuItemId, $method);
    }


    public function getHtml(int $menuId, string $displayType, bool $applyAcl = true){

        $currentRoute = $this->app->getRoute();
        $menu = $this->menusById[$menuId] ?? null;

        // Un menu supprimé peut rester référencé par une page ou une template :
        // le rendu devient vide au lieu de provoquer une erreur publique.
        if ($menu === null) return '';

        $menuItems = $menu->items;

        // La projection locale s'applique au HTML comme aux routes publiques.
        $locale = $this->corePublicLocaleHelper->locale();
        if ($locale !== null) {
            $itemsById = [];
            foreach ($menuItems as $item) $itemsById[$item->id] = $item;

            $menuItems = array_filter($menuItems, fn (MenuItem $item) =>
                $this->coreMenuLanguageHelper->isVisible($item, $locale, $itemsById));
        }

        // ACL
        if ($applyAcl)
        {
            $menuItems = array_filter($menuItems, function($item) {
                if ($item->access_role_id == null) return true;
                return $this->coreAclHelper->hasGlobalRole($item->access_role_id);
            });
        }

        $html = '';

        $menuItems = array_values(array_filter($menuItems, fn ($item) => $item->display_in_menu));

        usort($menuItems, fn (MenuItem $a, MenuItem $b) => $a->ordering <=> $b->ordering);

        if ($displayType == 'main')
        {
            
            /** @var array<int, array<MenuItem>> $itemsByParent */
            $itemsByParent = [];
            foreach ($menuItems as $item) {
                $itemsByParent[$item->parent_id ?? 0][] = $item;
            }

            $html .= '<ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">';

            foreach($itemsByParent[0] ?? [] as $item)
            {
                if ($this->isSeparator($item)) {
                    $html .= '<li><hr class="dropdown-divider"></li>';
                    continue;
                }

                $routeName = $this->coreMenuRouteHelper->getRouteNameFromMenuItem($item->id);
                $url = $this->coreMenuRouteHelper->getUrlFromMenuItem($item->id);
                $children = $itemsByParent[$item->id] ?? [];

                $active = $currentRoute->name == $routeName;
                foreach ($children as $child) {
                    $childRouteName = $this->coreMenuRouteHelper->getRouteNameFromMenuItem($child->id);
                    if ($currentRoute->name == $childRouteName) {
                        $active = true;
                        break;
                    }
                }

                if ($children !== []) {
                    $html .= '<li class="nav-item dropdown">';
                    $html .= $url === null
                        ? '<span class="nav-link dropdown-toggle' . ($active ? ' active' : '') . '" role="button" data-bs-toggle="dropdown" aria-expanded="false" tabindex="0">'
                        : '<a class="nav-link dropdown-toggle' . ($active ? ' active' : '') . '" href="' . HtmlTools::escape($url) . '" role="button" data-bs-toggle="dropdown" aria-expanded="false">';
                    $html .= $this->getIconHtml($item);
                    $html .= HtmlTools::escape($item->title);
                    $html .= $url === null ? '</span><ul class="dropdown-menu">' : '</a><ul class="dropdown-menu">';

                    foreach ($children as $child) {
                        if ($this->isSeparator($child)) {
                            $html .= '<li><hr class="dropdown-divider"></li>';
                            continue;
                        }

                        $childRouteName = $this->coreMenuRouteHelper->getRouteNameFromMenuItem($child->id);
                        $childUrl = $this->coreMenuRouteHelper->getUrlFromMenuItem($child->id);
                        $childActive = $currentRoute->name == $childRouteName ? ' active' : '';

                        $html .= '<li>';
                        $html .= $childUrl === null
                            ? '<span class="dropdown-item-text' . $childActive . '">'
                            : '<a class="dropdown-item' . $childActive . '" href="' . HtmlTools::escape($childUrl) . '">';
                        $html .= $this->getIconHtml($child);
                        $html .= HtmlTools::escape($child->title);
                        $html .= $childUrl === null ? '</span></li>' : '</a></li>';
                    }

                    $html .= '</ul></li>';
                } else {
                    $html .= '<li class="nav-item">';
                    $html .= $url === null
                        ? '<span class="nav-link' . ($active ? ' active' : '') . '">'
                        : '<a class="nav-link' . ($active ? ' active' : '') . '" aria-current="page" href="' . HtmlTools::escape($url) . '">';
                    $html .= $this->getIconHtml($item);
                    $html .= HtmlTools::escape($item->title);
                    $html .= $url === null ? '</span></li>' : '</a></li>';
                }
            }

            $html .= '</ul>';
        } else if ($displayType == 'simple') {
            $html .= '<div>';

            foreach($menuItems as $item)
            {
                if ($item->display_in_menu == false) continue;

                if ($this->isSeparator($item)) {
                    $html .= '<hr class="dropdown-divider">';
                    continue;
                }

                $url = $this->coreMenuRouteHelper->getUrlFromMenuItem($item->id);

                $html .= '<div>';
                $html .= $url === null
                    ? '<span>'
                    : '<a href="' . HtmlTools::escape($url) . '">';
                $html .= HtmlTools::escape($item->title);
                $html .= $url === null ? '</span></div>' : '</a></div>';
            }

            $html .= '</div>';
        }


        return $html;
    }

    private function getIconHtml(MenuItem $item): string
    {
        if ($item->icon != '') return '<i class="' . HtmlTools::escape($item->icon) . ' me-1"></i>';
        return '';
    }

    private function isSeparator(MenuItem $item): bool
    {
        return $item->extension === 'core' && $item->type === 'separator';
    }


    /**
     * 
     * @return ?array{0: Menu|null, 1: MenuItem|null} 
     */
    public function getCurrentMenu() : ?array{

        $currentRoute = $this->app->getRoute();
        $id = $this->coreMenuRouteHelper->getMenuItemIdByRouteName($currentRoute->name, 'GET');

        if ($id == null) return [null, null];
        
        return $this->getMenuItemAndMenu($id);
    }
}

