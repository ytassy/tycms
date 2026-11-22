<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Menu;

use YTassy\Tycms\CoreExts\Core\Controllers\MenuController;
use YTassy\Tycms\CoreExts\Core\Controllers\HomePlaceholderController;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Models\Menu;
use YTassy\Tycms\CoreExts\Core\Models\MenuItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Menu\MenuItemDef;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Routing\Router;
use YTassy\Tycms\WF\BaseHelper;

class MenuRouteHelper extends BaseHelper
{
    
    use TraitAllHelpers;

    /**
     * @var array<string,MenuItemDef>
     */
    public array $menuDefs = [];

    /**
     * @var array<int,array<string,string>>
     */
    public array $routeNameByMenuItemId = [];

    /**
     * @var array<string,array<string,int>>
     */
    public array $menuItemIdByRouteName = [];

    /** @var array<string, array{menu: Menu, menu_item: MenuItem}> */
    private array $legacyPublicPaths = [];


    public function addMenuType(MenuItemDef $def){

        $this->menuDefs[$def->extension . '__' . $def->type] = $def;
    }

    public function getAllMenusDef() : array{
        return $this->menuDefs;
    }

    
    public function registerRoutes(Router $router)
    {
        $menus = $this->coreMenuHelper->getMenusAnItemsOfSpace();

        // Add route for current Space
        $space = $this->coreSpaceHelper->getCurrentSpace();

        $locale = $this->corePublicLocaleHelper->locale();
        if ($locale === null) return;

        $homeItemId = $this->coreSpaceLanguageHelper->getHomeMenuItemId($space->id, $locale);
        [$menuHome, $menuHomeItem] = $homeItemId === null
            ? [null, null]
            : $this->coreMenuHelper->getMenuItemAndMenu($homeItemId);
        //[$menuUserSpace, $menuUserSpaceItem] = $this->coreMenuHelper->getMenuItemAndMenu($space->menu_item_user_space_id);

        // Un Space encore sans accueil expose une page provisoire ainsi que ses autres pages.
        if ($menuHome !== null && $menuHomeItem !== null) {
            $this->addMenuItemRoute($menuHome, $menuHomeItem, '/');
        } else {
            $router->registerRoute(
                'core_home_unconfigured',
                RequestMethod::GET,
                $this->corePublicLocaleHelper->publicPath('/'),
                new HomePlaceholderController($this),
                'show',
                [],
                false,
                [],
            );
        }

        $i = 0;
        foreach($menus as $menu)
        {
            if ($menu->space_id != $space->id) continue; // Ignore Menu not in space

            /** @var array<int, MenuItem> $menuItemsById */
            $menuItemsById = [];
            foreach ($menu->items as $item) {
                $menuItemsById[$item->id] = $item;
            }

            foreach($menu->items as $menuItem)
            {
                if ($menuHomeItem?->id == $menuItem->id) continue;

                // L'entrée et ses parents doivent être visibles dans cette langue.
                if (!$this->coreMenuLanguageHelper->isVisible($menuItem, $locale, $menuItemsById)) continue;

                $menuDef = $this->menuDefs[$menuItem->extension . '__' . $menuItem->type] ?? [];
                if ($menuDef == null) continue; //Def Introuvable

                // No Controller => No route
                if ($menuDef->controller == null) continue; 

                // No Controller => Route defined By Controller.
                if ($menuDef->controllerUrlDefined != null) continue; 

                $i++;
                $path = $this->getMenuItemPath($menu, $menuItem, $menuItemsById);

                if ($path == '/') continue;


                $this->addMenuItemRoute($menu, $menuItem, $path);
            }
        }


    }

    /**
     * Builds the route path from the menu alias and every ancestor alias.
     *
     * @param array<int, MenuItem> $menuItemsById
     */
    private function getMenuItemPath(Menu $menu, MenuItem $menuItem, array $menuItemsById): string
    {
        $parentAliases = [];
        $seenParentIds = [];
        $parentId = $menuItem->parent_id;

        while ($parentId !== null && !isset($seenParentIds[$parentId])) {
            $seenParentIds[$parentId] = true;
            $parent = $menuItemsById[$parentId] ?? null;

            if ($parent === null) break;

            $parentAliases[] = $parent->alias;
            $parentId = $parent->parent_id;
        }

        $pathParts = [
            $menu->alias,
            ...array_reverse($parentAliases),
            $menuItem->alias,
        ];

        $pathParts = array_filter($pathParts, static fn (string $alias) => trim($alias, '/') !== '');

        return '/' . implode('/', array_map(static fn (string $alias) => trim($alias, '/'), $pathParts));
    }

    private function addMenuItemRoute(Menu $menu, MenuItem $menuItem, string $path){

        // Get définition de menu
        $menuDef = $this->getMenuItemDef($menuItem);

        if ($menuDef == null) return; // Menu introuvable

        $publicPath = $this->corePublicLocaleHelper->publicPath($path);
        if ($publicPath === null) return;

        // Le chemin historique sert uniquement à orienter les anciens liens GET.
        if (isset($menuDef->methodsByHttpMethods['GET'])) {
            $this->legacyPublicPaths[$path] = ['menu' => $menu, 'menu_item' => $menuItem];
        }

        foreach($menuDef->methodsByHttpMethods ?? [] as $httpMethod => $method) {

            $routeName = 'core_menu_' . $menuItem->id . '_'. strtolower($httpMethod);

            $this->routeNameByMenuItemId[$menuItem->id][strtolower($httpMethod)] = $routeName;
            $this->menuItemIdByRouteName[$routeName][strtolower($httpMethod)] = $menuItem->id;

            $this->router->registerRoute($routeName, RequestMethod::from($httpMethod), 
                $publicPath, $menuDef->controller, $method, [], false, [
                    'menu' => $menu,
                    'menu_item' => $menuItem,
                ]);
        }

    }

    public function isLegacyPublicPath(string $path): bool
    {
        return isset($this->legacyPublicPaths[$path]);
    }

    /** @return array{menu: Menu, menu_item: MenuItem}|null */
    public function getLegacyPublicRouteData(string $path): ?array
    {
        return $this->legacyPublicPaths[$path] ?? null;
    }

    public function getMenuItemDef(MenuItem $menuItem) : ?MenuItemDef{
        return $this->menuDefs[$menuItem->extension . '__' . $menuItem->type] ?? null;
    }

    public function getMenuItemDefFromId(int $menuItemId) : ?MenuItemDef{
        [$menu, $menuItem] = $this->coreMenuHelper->getMenuItemAndMenu($menuItemId);
        if ($menuItem == null) return null;
        return $this->getMenuItemDef($menuItem);
    }

    

    public function getRouteNameFromMenuItem(int $menuItemId, string $method = 'GET'): ?string{
        $p1 = $this->routeNameByMenuItemId[$menuItemId] ?? null;

        if ($p1 == null) {
            // Check Controller Route Name
            $def = $this->getMenuItemDefFromId($menuItemId);

            if ($def == null) return null;
            return $def->controllerRouteName;
        }
    
        return $p1[strtolower($method)] ?? null;  
    }

    public function getUrlFromMenuItem(int $menuItemId, string $method = 'GET'): ?string{
        $routeName = $this->getRouteNameFromMenuItem($menuItemId, $method);
        if ($routeName == null) return null;
        return $this->router->url($routeName);
    }

    /** Résoudre une destination sans dépendre des routes du Space/langue de la requête. */
    public function getUrlForContext(int $itemId, int $spaceId, string $locale, bool $applyAcl = true): ?string
    {
        [$menu, $item] = $this->coreMenuHelper->getMenuItemAndMenu($itemId);
        if ($menu === null || $item === null || $menu->space_id !== $spaceId || $item->status !== 1) return null;
        $languages = $this->contextLanguages[$spaceId] ??= array_column(
            $this->coreSpaceLanguageHelper->listActiveForSpace($spaceId), 'locale',
        );
        if (!in_array($locale, $languages, true)) return null;

        // Contrôler toute la chaîne, y compris un parent dépublié ou supprimé.
        $byId = [];
        foreach ($menu->items as $candidate) $byId[$candidate->id] = $candidate;
        $seen = [];
        $current = $item;
        while (true) {
            if (isset($seen[$current->id]) || $current->status !== 1) return null;
            $seen[$current->id] = true;
            if ($applyAcl && $current->access_role_id !== null
                && !$this->coreAclHelper->hasGlobalRole($current->access_role_id, $spaceId)) return null;
            if ($current->parent_id === null) break;
            $current = $byId[$current->parent_id] ?? null;
            if ($current === null) return null;
        }
        if (!$this->coreMenuLanguageHelper->isVisible($item, $locale, $byId)) return null;
        $def = $this->getMenuItemDef($item);
        if ($def?->controller === null || !isset($def->methodsByHttpMethods['GET'])) return null;

        // Les routes techniques déclarées par un contrôleur gardent leur chemin propre.
        if ($def->controllerUrlDefined !== null) {
            $path = $def->controllerUrlDefined;
            return str_contains($path, '{') || str_contains($path, '}') ? null : $path;
        }
        $homeKey = $spaceId . ':' . $locale;
        if (!array_key_exists($homeKey, $this->contextHomes)) {
            $this->contextHomes[$homeKey] = $this->coreSpaceLanguageHelper->getHomeMenuItemId($spaceId, $locale);
        }
        $path = $this->contextHomes[$homeKey] === $itemId ? '/' : $this->getMenuItemPath($menu, $item, $byId);
        if ($path === '/' && $this->contextHomes[$homeKey] !== $itemId) return null;
        return '/' . $locale . ($path === '/' ? '/' : $path);
    }

    /** @var array<int,list<string>> */
    private array $contextLanguages = [];
    /** @var array<string,int|null> */
    private array $contextHomes = [];

    public function getMenuItemIdByRouteName(string $routeName, string $httpMethod) : ?int{
        return $this->menuItemIdByRouteName[$routeName][strtolower($httpMethod)] ?? null;
    }

}
