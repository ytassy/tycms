<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Menu;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Models\Menu;
use YTassy\Tycms\CoreExts\Core\Models\MenuItem;
use YTassy\Tycms\WF\BaseHelper;

class MenuAdminHelper extends BaseHelper
{
    use TraitAllHelpers;


    public function __construct(BaseApplicationItem|Application $app)
    {
        parent::__construct($app);


    }

    public function getMenuForItem(int $menuItemId): ?Menu
    {
        return $this->db->selectOrmOneOrNull(Menu::class, <<<'SQL'
            SELECT menus.*
            FROM menus
            INNER JOIN menu_items ON menu_items.menu_id = menus.id
            WHERE menu_items.id = %
            SQL, [$menuItemId]);
    }

    public function getMenu(int $menuId): ?Menu
    {
        return $this->db->selectOrmOneOrNull(Menu::class, 'SELECT * FROM menus WHERE id = %', [$menuId]);
    }

    public function hasMenuName(int $spaceId, bool $userDefined, string $name, ?int $exceptMenuId = null): bool
    {
        $sql = 'SELECT id FROM menus WHERE space_id = % AND user_defined = % AND name = %';
        $params = [$spaceId, $userDefined, $name];
        if ($exceptMenuId !== null) {
            $sql .= ' AND id != %';
            $params[] = $exceptMenuId;
        }

        return $this->db->selectOneOrNull($sql . ' LIMIT 1', $params) !== null;
    }

    public function createMenu(object $data): Menu
    {
        $this->db->execute(
            'INSERT INTO menus (space_id, user_defined, name, description, alias) VALUES (%, %, %, %, %)',
            [$data->space_id, $data->user_defined, $data->name, $data->description, $data->alias],
        );

        return $this->getMenu($this->db->getLastInsertId());
    }

    public function updateMenu(int $menuId, object $data): Menu
    {
        $this->db->execute(
            'UPDATE menus SET name = %, description = %, alias = %, updated = CURRENT_TIMESTAMP WHERE id = %',
            [$data->name, $data->description, $data->alias, $menuId],
        );

        return $this->getMenu($menuId);
    }

    /** @return array<int, Menu> */
    public function getMenusAndItemsOfSpace(int $spaceId): array
    {
        $menus = $this->db->selectOrm(
            Menu::class,
            'SELECT * FROM menus WHERE space_id = %',
            [$spaceId],
        );
        $menusById = [];

        foreach ($menus as $menu) {
            $menusById[$menu->id] = $menu;
        }

        $items = $this->db->selectOrm(
            MenuItem::class,
            'SELECT menu_items.* FROM menu_items INNER JOIN menus ON menus.id = menu_items.menu_id WHERE menus.space_id = %',
            [$spaceId],
        );

        foreach ($items as $item) {
            $menusById[$item->menu_id]->items[] = $item;
        }

        return $menusById;
    }

    public function getItem(int $menuItemId): ?MenuItem
    {
        return $this->db->selectOrmOneOrNull(
            MenuItem::class,
            'SELECT * FROM menu_items WHERE id = %',
            [$menuItemId],
        );
    }

    public function isSpaceHomeItem(int $menuItemId): bool
    {
        return $this->db->selectOneOrNull(
            'SELECT id FROM spaces WHERE menu_item_home_id = % LIMIT 1',
            [$menuItemId],
        ) !== null || $this->db->selectOneOrNull(
            'SELECT id FROM space_languages WHERE menu_item_home_id = % LIMIT 1',
            [$menuItemId],
        ) !== null;
    }

    public function isSpaceHomeItemForLocale(int $menuItemId, string $locale): bool
    {
        return $this->db->selectOneOrNull(<<<SQL
            SELECT space_languages.id
            FROM space_languages
            INNER JOIN spaces ON spaces.id = space_languages.space_id
            WHERE space_languages.locale = % AND space_languages.enabled = 1
                AND COALESCE(space_languages.menu_item_home_id, spaces.menu_item_home_id) = %
            LIMIT 1
            SQL, [$locale, $menuItemId]) !== null;
    }

    public function isSpaceUserSpaceItem(int $menuItemId): bool
    {
        return $this->db->selectOneOrNull(
            'SELECT id FROM spaces WHERE menu_item_user_space_id = % LIMIT 1',
            [$menuItemId],
        ) !== null || $this->db->selectOneOrNull(
            'SELECT id FROM space_languages WHERE menu_item_user_space_id = % LIMIT 1',
            [$menuItemId],
        ) !== null;
    }

    public function isSpaceUserSpaceItemForLocale(int $menuItemId, string $locale): bool
    {
        return $this->db->selectOneOrNull(<<<SQL
            SELECT space_languages.id
            FROM space_languages
            INNER JOIN spaces ON spaces.id = space_languages.space_id
            WHERE space_languages.locale = % AND space_languages.enabled = 1
                AND COALESCE(space_languages.menu_item_user_space_id, spaces.menu_item_user_space_id) = %
            LIMIT 1
            SQL, [$locale, $menuItemId]) !== null;
    }

    public function createItem(int $menuId, object $data): MenuItem
    {
        $nextOrdering = $this->db->selectOneOrNull(
            'SELECT COALESCE(MAX(ordering), 0) + 10 AS ordering FROM menu_items WHERE menu_id = % AND parent_id <=> %',
            [$menuId, $data->parent_id],
        );

        $this->db->execute(
            'INSERT INTO menu_items (menu_id, ordering, parent_id, status, extension, type, params, title, alias, css, icon, display_in_menu, browser_title, meta_description, meta_robots, page_title, access_role_id) VALUES (%, %, %, %, %, %, %, %, %, %, %, %, %, %, %, %, %)',
            [
                $menuId,
                (int) $nextOrdering->ordering,
                $data->parent_id,
                $data->status,
                $data->extension,
                $data->type,
                json_encode($data->params, JSON_THROW_ON_ERROR),
                $data->title,
                $data->alias,
                $data->css,
                $data->icon,
                $data->display_in_menu,
                $data->browser_title,
                $data->meta_description,
                $data->meta_robots,
                $data->page_title,
                $data->access_role_id,
            ],
        );

        return $this->getItem($this->db->getLastInsertId());
    }

    public function updateItem(int $menuItemId, object $data): MenuItem
    {
        $this->db->execute(
            'UPDATE menu_items SET parent_id = %, status = %, extension = %, type = %, params = %, title = %, alias = %, css = %, icon = %, display_in_menu = %, browser_title = %, meta_description = %, meta_robots = %, page_title = %, access_role_id = % WHERE id = %',
            [
                $data->parent_id,
                $data->status,
                $data->extension,
                $data->type,
                json_encode($data->params, JSON_THROW_ON_ERROR),
                $data->title,
                $data->alias,
                $data->css,
                $data->icon,
                $data->display_in_menu,
                $data->browser_title,
                $data->meta_description,
                $data->meta_robots,
                $data->page_title,
                $data->access_role_id,
                $menuItemId,
            ],
        );

        return $this->getItem($menuItemId);
    }

    /** @param list<array{locale:string,enabled:bool}> $languages */
    public function saveItemLanguages(int $itemId, array $languages): void
    {
        foreach ($languages as $language) {
            // Une ligne inutile est supprimée : les entrées existantes restent
            // visibles partout tant qu'aucune exception n'est configurée.
            if ($language['enabled']) {
                $this->db->execute(
                    'DELETE FROM menu_item_languages WHERE menu_item_id = % AND locale = %',
                    [$itemId, $language['locale']],
                );
                continue;
            }

            $this->db->execute(
                'INSERT INTO menu_item_languages (menu_item_id, locale, enabled) VALUES (%, %, %) '
                . 'ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)',
                [$itemId, $language['locale'], $language['enabled']],
            );
        }
    }

    public function wouldCreateParentCycle(int $menuItemId, int $parentId): bool
    {
        $visited = [];

        while (!isset($visited[$parentId])) {
            if ($parentId === $menuItemId) return true;

            $visited[$parentId] = true;
            $parent = $this->getItem($parentId);
            if ($parent === null || $parent->parent_id === null) return false;

            $parentId = $parent->parent_id;
        }

        return true;
    }

    public function updateItemStatus(int $menuItemId, int $status): bool
    {
        return $this->db->execute(
            'UPDATE menu_items SET status = % WHERE id = %',
            [$status, $menuItemId],
        ) >= 0;
    }

    public function deleteTrashedItem(int $menuItemId): bool
    {
        return $this->db->execute(
            'DELETE FROM menu_items WHERE id = % AND status = -2',
            [$menuItemId],
        ) === 1;
    }

    public function moveItemUp(int $menuItemId): bool
    {
        return $this->moveItem($menuItemId, -1);
    }

    public function moveItemDown(int $menuItemId): bool
    {
        return $this->moveItem($menuItemId, 1);
    }

    private function moveItem(int $menuItemId, int $offset): bool
    {
        $item = $this->db->selectOrmOneOrNull(
            MenuItem::class,
            'SELECT * FROM menu_items WHERE id = % FOR UPDATE',
            [$menuItemId],
        );

        if ($item === null) return false;

        $siblings = $this->db->selectOrm(
            MenuItem::class,
            'SELECT * FROM menu_items WHERE menu_id = % AND parent_id <=> % ORDER BY ordering, id FOR UPDATE',
            [$item->menu_id, $item->parent_id],
        );

        $currentIndex = array_find_key($siblings, fn (MenuItem $sibling) => $sibling->id === $item->id);
        $targetIndex = $currentIndex === null ? null : $currentIndex + $offset;

        if ($targetIndex === null || !isset($siblings[$targetIndex])) return false;

        [$siblings[$currentIndex], $siblings[$targetIndex]] = [$siblings[$targetIndex], $siblings[$currentIndex]];
        $this->normalizeOrderings($siblings);

        return true;
    }

    /** @param array<MenuItem> $items */
    private function normalizeOrderings(array $items): void
    {
        $cases = [];
        $values = [];
        $ids = [];

        foreach ($items as $index => $item) {
            $cases[] = 'WHEN % THEN %';
            $values[] = $item->id;
            $values[] = ($index + 1) * 10;
            $ids[] = '%';
        }

        $values = [...$values, ...array_map(fn (MenuItem $item) => $item->id, $items)];
        $this->db->execute(
            'UPDATE menu_items SET ordering = CASE id ' . implode(' ', $cases) . ' END WHERE id IN (' . implode(', ', $ids) . ')',
            $values,
        );
    }
}

