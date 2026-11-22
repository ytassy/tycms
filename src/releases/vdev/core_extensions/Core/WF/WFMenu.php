<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Models\Menu;
use YTassy\Tycms\CoreExts\Core\Models\MenuItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

class WFMenu extends BaseWF
{
    use TraitAllHelpers;
    
    /**
     * @return array<string,Menu>
     */
    public function getMenus(): array
    {
        $spaces = $this->coreAclHelper->getSpacesHavingGlobalPermission(Permissions::MENU_READ);

        $res = [];

        foreach ($spaces as $spaceId) {
            $menus = $this->coreMenuAdminHelper->getMenusAndItemsOfSpace($spaceId);
            $languages = $this->coreSpaceLanguageHelper->listActiveForSpace($spaceId);

            // Chaque menu porte sa propre hiérarchie de parents.
            foreach ($menus as $menu) {
                $itemsById = [];
                foreach ($menu->items as $item) {
                    $itemsById[$item->id] = $item;
                }

                // Fournir au tableau le choix propre et son résultat après les parents.
                foreach ($itemsById as $item) {
                    foreach ($languages as $language) {
                        $locale = $language->locale;
                        $item->languages[] = [
                            'locale' => $locale,
                            'enabled' => $this->coreMenuLanguageHelper->isEnabled($item, $locale),
                            'visible' => $this->coreMenuLanguageHelper->isVisible($item, $locale, $itemsById),
                        ];
                    }
                }
            }

            $res[$spaceId] = $menus;
        }

        return $res;
    }

    /**
     * Returns display metadata for every space whose menus the current user
     * may read, together with the roles available from those spaces.
     *
     * @return array{spaces:array<int,array{id:int,title:string}>,roles:array<int,mixed>}
     */
    public function getMenuDirectory(): array
    {
        $spaceIds = $this->coreAclHelper->getSpacesHavingGlobalPermission(Permissions::MENU_READ);
        $spaces = [];
        $roles = [];

        foreach ($this->coreSpaceHelper->getSpacesByIds($spaceIds) as $space) {
            $spaces[$space->id] = [
                'id' => $space->id,
                'title' => $space->title,
            ];

            foreach ($this->coreSpaceHelper->getRolesForSpace($space->id) as $roleId => $role) {
                $roles[$roleId] = $role;
            }
        }

        return ['spaces' => $spaces, 'roles' => $roles];
    }

    /** @return array{form:array<string,mixed>} */
    public function getMenuEditorForm(?int $menuId = null): array
    {
        $menu = $menuId === null ? null : $this->getUserDefinedMenuForCrud($menuId);
        $form = $this->coreMenuFormsHelper->getMenuForm($menu);
        $values = $menu === null ? [] : get_object_vars($menu);

        if ($menu !== null) {
            $values['space_title'] = $this->coreSpaceHelper->getSpace($menu->space_id)?->title
                ?? ('Espace #' . $menu->space_id);
        }

        return ['form' => $form->getDefForRia($values)];
    }

    public function createMenu(object $data): Menu
    {
        $data = $this->normalizeMenuData($data, null);
        if (!$this->coreAclHelper->hasGlobalPermission(Permissions::MENU_CRUD, $data->space_id)) {
            ExceptionResponse::throwDanger('Vous ne disposez pas des droits pour créer un menu dans cet espace.');
        }

        return $this->coreMenuAdminHelper->createMenu($data);
    }

    public function updateMenu(int $menuId, object $data): Menu
    {
        $menu = $this->getUserDefinedMenuForCrud($menuId);
        $data = $this->normalizeMenuData($data, $menu);

        return $this->coreMenuAdminHelper->updateMenu($menu->id, $data);
    }

    public function moveMenuItemUp(int $menuItemId): bool
    {
        return $this->moveMenuItem($menuItemId, true);
    }

    public function moveMenuItemDown(int $menuItemId): bool
    {
        return $this->moveMenuItem($menuItemId, false);
    }

    public function changeMenuItemStatus(int $menuItemId, string $status): bool
    {
        $item = $this->getMenuItemForCrud($menuItemId);
        $status = filter_var($status, FILTER_VALIDATE_INT);

        if ($status === false || !in_array($status, [1, 0, -1, -2], true)) {
            ExceptionResponse::throwWarning('Statut de menu invalide.');
        }

        $this->assertSpaceHomeItemIsPublished($item, $status);

        return $this->coreMenuAdminHelper->updateItemStatus($item->id, $status);
    }

    public function deleteMenuItem(int $menuItemId): bool
    {
        $item = $this->getMenuItemForCrud($menuItemId);

        $menu = $this->coreMenuAdminHelper->getMenuForItem($item->id);
        $space = $menu === null ? null : $this->coreSpaceHelper->getSpace($menu->space_id);
        $authReference = false;
        foreach ([$space?->menu_item_login_id, $space?->menu_item_register_id] as $requiredId) {
            $seen = [];
            while ($requiredId !== null && !isset($seen[$requiredId])) {
                if ($requiredId === $item->id) $authReference = true;
                $seen[$requiredId] = true;
                $requiredId = $this->coreMenuAdminHelper->getItem($requiredId)?->parent_id;
            }
        }
        if ($authReference || $this->isRequiredNavigationItemInAnyLocale($item)) {
            ExceptionResponse::throwWarning('Retirez d’abord cette entrée de la navigation du Space avant de la supprimer.');
        }

        if ($item->status !== -2) {
            ExceptionResponse::throwWarning('Seuls les éléments placés dans la corbeille peuvent être supprimés.');
        }

        if (!$this->coreMenuAdminHelper->deleteTrashedItem($item->id)) {
            ExceptionResponse::throwWarning('Élément de menu introuvable ou non placé dans la corbeille.');
        }

        return true;
    }

    public function createMenuItem(int $menuId, object $data): MenuItem
    {
        $menu = $this->getMenuForCrud($menuId);

        $languages = $this->normalizeItemLanguages($data->languages ?? null, $menu, null);

        $item = $this->coreMenuAdminHelper->createItem(
            $menu->id,
            $this->normalizeMenuItemData($data, $menu, null),
        );

        if ($languages !== null) $this->coreMenuAdminHelper->saveItemLanguages($item->id, $languages);

        return $item;
    }

    public function updateMenuItem(int $menuItemId, object $data): MenuItem
    {
        $item = $this->getMenuItemForCrud($menuItemId);
        $menu = $this->coreMenuAdminHelper->getMenuForItem($item->id);
        $languages = $this->normalizeItemLanguages($data->languages ?? null, $menu, $item);
        $data = $this->normalizeMenuItemData($data, $menu, $item);
        $this->assertSpaceHomeItemIsPublished($item, $data->status);

        $updated = $this->coreMenuAdminHelper->updateItem(
            $item->id,
            $data,
        );

        if ($languages !== null) $this->coreMenuAdminHelper->saveItemLanguages($item->id, $languages);

        return $updated;
    }

    /** @return array{form:array<string,mixed>,paramsForm:array<string,mixed>|null,languages:list<array{locale:string,enabled:bool}>,homeLocales:list<string>,userSpaceLocales:list<string>,requiredLocales:list<string>} */
    public function getMenuItemEditorForm(int $menuId, ?int $menuItemId = null, ?string $definitionKey = null): array
    {
        $menu = $this->getMenuForCrud($menuId);
        $item = null;
        if ($menuItemId !== null) {
            $item = $this->getMenuItemForCrud($menuItemId);
            if ($item->menu_id !== $menu->id) {
                ExceptionResponse::throwWarning('L\'élément de menu n\'appartient pas au menu sélectionné.');
            }
        }

        $storedDefinitionKey = $item === null ? null : $item->extension . '__' . $item->type;
        $activeDefinitionKey = $definitionKey ?? $storedDefinitionKey;
        if ($activeDefinitionKey !== null && !isset($this->coreMenuRouteHelper->getAllMenusDef()[$activeDefinitionKey])) {
            ExceptionResponse::throwWarning('Type d\'élément de menu invalide.');
        }

        $form = $this->coreMenuFormsHelper->getMenuItemForm($menu, $item);
        $values = $item === null ? [] : get_object_vars($item);
        if (($values['access_role_id'] ?? null) === null) {
            $values['access_role_id'] = 0;
        }
        if ($activeDefinitionKey !== null) {
            $values['definitionKey'] = $activeDefinitionKey;
        }

        $paramsForm = $activeDefinitionKey === null
            ? null
            : $this->coreMenuFormsHelper->getMenuItemParamsForm($activeDefinitionKey);
        $paramsValues = $item !== null && $activeDefinitionKey === $storedDefinitionKey
            ? $this->getMenuItemParamsValues($item->params)
            : [];

        // Seules les langues actives du Space sont éditables. Une ancienne
        // configuration dans une langue inactive reste conservée en base.
        $overrides = $item === null ? [] : $this->coreMenuLanguageHelper->listForItem($item->id);
        $languages = [];
        $homeLocales = [];
        $userSpaceLocales = [];
        $requiredLocales = [];
        foreach ($this->coreSpaceLanguageHelper->listActiveForSpace($menu->space_id) as $language) {
            $languages[] = [
                'locale' => $language->locale,
                'enabled' => $overrides[$language->locale] ?? true,
            ];

            if ($item !== null && $this->coreMenuAdminHelper->isSpaceHomeItemForLocale($item->id, $language->locale)) {
                $homeLocales[] = $language->locale;
            }
            if ($item !== null && $this->coreMenuAdminHelper->isSpaceUserSpaceItemForLocale($item->id, $language->locale)) {
                $userSpaceLocales[] = $language->locale;
            }
            if ($item !== null && $this->isRequiredNavigationItemForLocale($item, $menu->space_id, $language->locale)) {
                $requiredLocales[] = $language->locale;
            }
        }

        return [
            'form' => $form->getDefForRia($values, ['spaceId' => $menu->space_id]),
            'paramsForm' => $paramsForm?->getDefForRia($paramsValues, ['spaceId' => $menu->space_id]),
            'languages' => $languages,
            'homeLocales' => $homeLocales,
            'userSpaceLocales' => $userSpaceLocales,
            'requiredLocales' => $requiredLocales,
        ];
    }

    /** @return list<array{locale:string,enabled:bool}>|null */
    private function normalizeItemLanguages(mixed $input, Menu $menu, ?MenuItem $item): ?array
    {
        if ($input === null) return null;
        if (!is_array($input)) ExceptionResponse::throwWarning('Langues de menu invalides.');

        $active = [];
        foreach ($this->coreSpaceLanguageHelper->listActiveForSpace($menu->space_id) as $language) {
            $active[$language->locale] = true;
        }

        $result = [];
        $seen = [];
        foreach ($input as $language) {
            if (!is_object($language)
                || !is_string($language->locale ?? null)
                || !isset($active[$language->locale])
                || isset($seen[$language->locale])
                || !is_bool($language->enabled ?? null)) {
                ExceptionResponse::throwWarning('Configuration de langue du menu invalide.');
            }

            $seen[$language->locale] = true;
            if ($item !== null && !$language->enabled
                && $this->isRequiredNavigationItemForLocale($item, $menu->space_id, $language->locale)) {
                ExceptionResponse::throwWarning('Une entrée de navigation du Space et ses parents doivent rester visibles dans leur langue.');
            }

            $result[] = [
                'locale' => $language->locale,
                'enabled' => $language->enabled,
            ];
        }

        if (count($result) !== count($active)) {
            ExceptionResponse::throwWarning('Chaque langue active du Space doit être renseignée.');
        }

        return $result;
    }

    private function moveMenuItem(int $menuItemId, bool $up): bool
    {
        $item = $this->getMenuItemForCrud($menuItemId);

        return $up
            ? $this->coreMenuAdminHelper->moveItemUp($item->id)
            : $this->coreMenuAdminHelper->moveItemDown($item->id);
    }

    private function getMenuItemForCrud(int $menuItemId): MenuItem
    {
        if ($menuItemId < 1) {
            ExceptionResponse::throwWarning('Élément de menu invalide.');
        }

        $menu = $this->coreMenuAdminHelper->getMenuForItem($menuItemId);
        if ($menu === null) {
            ExceptionResponse::throwWarning('Élément de menu introuvable.');
        }

        if (!$this->coreAclHelper->hasGlobalPermission(Permissions::MENU_CRUD, $menu->space_id)) {
            ExceptionResponse::throwDanger('Vous ne disposez pas des droits pour modifier ce menu.');
        }

        $this->assertMenuIsUserDefined($menu);

        $item = $this->coreMenuAdminHelper->getItem($menuItemId);
        if ($item === null) {
            ExceptionResponse::throwWarning('Élément de menu introuvable.');
        }

        return $item;
    }

    private function getMenuForCrud(int $menuId): Menu
    {
        if ($menuId < 1) {
            ExceptionResponse::throwWarning('Menu invalide.');
        }

        $menu = $this->coreMenuAdminHelper->getMenu($menuId);
        if ($menu === null) {
            ExceptionResponse::throwWarning('Menu introuvable.');
        }

        if (!$this->coreAclHelper->hasGlobalPermission(Permissions::MENU_CRUD, $menu->space_id)) {
            ExceptionResponse::throwDanger('Vous ne disposez pas des droits pour modifier ce menu.');
        }

        $this->assertMenuIsUserDefined($menu);

        return $menu;
    }

    private function getUserDefinedMenuForCrud(int $menuId): Menu
    {
        return $this->getMenuForCrud($menuId);
    }

    private function assertMenuIsUserDefined(Menu $menu): void
    {
        if (!$menu->user_defined) {
            ExceptionResponse::throwWarning('Ce menu système ne peut pas être modifié.');
        }
    }

    private function normalizeMenuData(object $data, ?Menu $menu): object
    {
        $form = $this->coreMenuFormsHelper->getMenuForm($menu);
        $validation = $form->validateSubmittedValues($data);
        if (!$validation->isValid()) {
            $message = $validation->getFirstError()?->message ?? 'valeur non autorisée.';
            ExceptionResponse::throwWarning('Les données du menu sont invalides : ' . $message);
        }

        /** @var object $values */
        $values = $validation->value;
        $values = (object) $form->getInitialValues(get_object_vars($values));

        if ($menu === null) {
            $spaceId = filter_var($values->space_id ?? null, FILTER_VALIDATE_INT);
            if ($spaceId === false || $spaceId < 1 || !isset($this->coreSpaceHelper->getAllSpacesByIds()[$spaceId])) {
                ExceptionResponse::throwWarning('Espace de menu invalide.');
            }
        } else {
            $spaceId = $menu->space_id;
        }

        $name = trim((string) ($values->name ?? ''));
        if ($name === '') {
            ExceptionResponse::throwWarning('Le nom du menu est obligatoire.');
        }

        if ($this->coreMenuAdminHelper->hasMenuName($spaceId, true, $name, $menu?->id)) {
            ExceptionResponse::throwWarning('Un menu défini par un utilisateur porte déjà ce nom dans cet espace.');
        }

        return (object) [
            'space_id' => $spaceId,
            'user_defined' => true,
            'name' => $name,
            'alias' => trim((string) ($values->alias ?? '')),
            'description' => trim((string) ($values->description ?? '')),
        ];
    }

    /**
     * @return object
     */
    private function normalizeMenuItemData(object $data, Menu $menu, ?MenuItem $item): object
    {
        $data = $this->validateMenuItemFormData($data, $menu, $item);

        $title = trim((string) ($data->title ?? $item?->title ?? ''));
        if ($title === '') {
            ExceptionResponse::throwWarning('Le titre du menu est obligatoire.');
        }

        $extension = trim((string) ($data->extension ?? $item?->extension ?? ''));
        $type = trim((string) ($data->type ?? $item?->type ?? ''));
        if (!isset($this->coreMenuRouteHelper->getAllMenusDef()[$extension . '__' . $type])) {
            ExceptionResponse::throwWarning('Type d\'élément de menu invalide.');
        }

        $parentId = $this->normalizeNullableId($data->parent_id ?? $item?->parent_id ?? null);
        if ($parentId !== null) {
            $parent = $this->coreMenuAdminHelper->getItem($parentId);
            if ($parent === null || $parent->menu_id !== $menu->id) {
                ExceptionResponse::throwWarning('Le parent sélectionné n\'appartient pas à ce menu.');
            }

            if ($item !== null && $this->coreMenuAdminHelper->wouldCreateParentCycle($item->id, $parentId)) {
                ExceptionResponse::throwWarning('Un élément de menu ne peut pas être son propre parent ou enfant.');
            }
        }

        $status = filter_var($data->status ?? $item?->status ?? 1, FILTER_VALIDATE_INT);
        if ($status === false || !in_array($status, [1, 0, -1, -2], true)) {
            ExceptionResponse::throwWarning('Statut de menu invalide.');
        }

        $params = $data->params ?? $item?->params ?? [];
        if (!is_array($params) && !is_object($params)) {
            ExceptionResponse::throwWarning('Les paramètres de l\'élément doivent être un objet ou un tableau JSON.');
        }

        $metaRobots = trim((string) ($data->meta_robots ?? $item?->meta_robots ?? 'index, follow'));
        if (!in_array($metaRobots, ['index, follow', 'noindex, follow', 'index, nofollow', 'noindex, nofollow'], true)) {
            ExceptionResponse::throwWarning('Valeur Meta robots invalide.');
        }

        return (object) [
            'parent_id' => $parentId,
            'status' => $status,
            'extension' => $extension,
            'type' => $type,
            'params' => $params,
            'title' => $title,
            'alias' => trim((string) ($data->alias ?? $item?->alias ?? '')),
            'css' => trim((string) ($data->css ?? $item?->css ?? '')),
            'icon' => trim((string) ($data->icon ?? $item?->icon ?? '')),
            'display_in_menu' => (bool) ($data->display_in_menu ?? $item?->display_in_menu ?? true),
            'browser_title' => trim((string) ($data->browser_title ?? $item?->browser_title ?? '')),
            'meta_description' => trim((string) ($data->meta_description ?? $item?->meta_description ?? '')),
            'meta_robots' => $metaRobots,
            'page_title' => trim((string) ($data->page_title ?? $item?->page_title ?? '')),
            'access_role_id' => $this->normalizeNullableId($data->access_role_id ?? $item?->access_role_id ?? null),
        ];
    }

    private function validateMenuItemFormData(object $data, Menu $menu, ?MenuItem $item): object
    {
        if (!property_exists($data, 'definitionKey')) {
            return $data;
        }

        $baseValues = get_object_vars($data);
        $params = $baseValues['params'] ?? new \stdClass();
        unset($baseValues['params'], $baseValues['languages']);

        $form = $this->coreMenuFormsHelper->getMenuItemForm($menu, $item);
        $validation = $form->validateSubmittedValues((object) $baseValues);
        if (!$validation->isValid()) {
            $message = $validation->getFirstError()?->message ?? 'valeur non autorisée.';
            ExceptionResponse::throwWarning('Les données de l\'élément de menu sont invalides : ' . $message);
        }

        /** @var object $values */
        $values = $validation->value;
        $definitionKey = $values->definitionKey;
        $definition = $this->coreMenuRouteHelper->getAllMenusDef()[$definitionKey] ?? null;
        if ($definition === null) {
            ExceptionResponse::throwWarning('Type d\'élément de menu invalide.');
        }

        $paramsForm = $this->coreMenuFormsHelper->getMenuItemParamsForm($definitionKey);
        if ($paramsForm === null) {
            $values->params = new \stdClass();
        } else {
            if (!is_object($params)) {
                ExceptionResponse::throwWarning('Les paramètres de l\'élément de menu sont invalides.');
            }

            $paramsValidation = $paramsForm->validateSubmittedValues($params);
            if (!$paramsValidation->isValid()) {
                $message = $paramsValidation->getFirstError()?->message ?? 'valeur non autorisée.';
                ExceptionResponse::throwWarning('Les paramètres de l\'élément de menu sont invalides : ' . $message);
            }

            /** @var object $normalisedParams */
            $normalisedParams = $paramsValidation->value;
            $values->params = (object) $paramsForm->getInitialValues(get_object_vars($normalisedParams));
        }

        // Une page liée doit être publiée dans le Space réel du menu.
        if ($definition->extension === 'plg_core' && $definition->type === 'page') {
            $this->assertPageMenuItemTarget($menu, $values->params);
        }

        unset($values->definitionKey);
        $values->extension = $definition->extension;
        $values->type = $definition->type;

        return $values;
    }

    /** Une entrée de menu de type page ne vise que la page publiée de son Space. */
    private function assertPageMenuItemTarget(Menu $menu, object $params): void
    {
        $pageId = $params->page_id ?? null;
        $page = is_int($pageId) ? $this->corePageHelper->getPage($pageId) : null;
        if ($page === null || $page->status !== 1 || $page->space_id !== $menu->space_id) {
            ExceptionResponse::throwWarning('La page choisie doit être publiée dans cet espace.');
        }
    }

    /** @return array<string,mixed> */
    private function getMenuItemParamsValues(mixed $params): array
    {
        if (is_object($params)) {
            return get_object_vars($params);
        }

        return is_array($params) ? $params : [];
    }

    private function normalizeNullableId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') return null;

        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            ExceptionResponse::throwWarning('Identifiant invalide.');
        }

        return $id;
    }

    private function assertSpaceHomeItemIsPublished(MenuItem $item, int $status): void
    {
        if ($status !== 1 && $this->isRequiredNavigationItemInAnyLocale($item)) {
            ExceptionResponse::throwWarning('Une entrée de navigation d’un espace doit rester publiée.');
        }
    }

    private function isRequiredNavigationItemInAnyLocale(MenuItem $item): bool
    {
        $menu = $this->coreMenuAdminHelper->getMenuForItem($item->id);
        if ($menu === null) return false;

        foreach ($this->coreSpaceLanguageHelper->listActiveForSpace($menu->space_id) as $language) {
            if ($this->isRequiredNavigationItemForLocale($item, $menu->space_id, $language->locale)) {
                return true;
            }
        }

        return false;
    }

    private function isRequiredNavigationItemForLocale(MenuItem $item, int $spaceId, string $locale): bool
    {
        $requiredIds = [
            $this->coreSpaceLanguageHelper->getHomeMenuItemId($spaceId, $locale),
            $this->coreSpaceLanguageHelper->getUserSpaceMenuItemId($spaceId, $locale),
        ];

        // Une entrée parente est nécessaire à la visibilité de toute sa branche.
        foreach ($requiredIds as $requiredId) {
            $seen = [];
            while ($requiredId !== null && !isset($seen[$requiredId])) {
                if ($requiredId === $item->id) return true;

                $seen[$requiredId] = true;
                $requiredId = $this->coreMenuAdminHelper->getItem($requiredId)?->parent_id;
            }
        }

        return false;
    }


}
