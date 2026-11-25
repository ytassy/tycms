<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Core\WF\WFMenu;

/**
 * Menus et entrées lisibles par l'appelant.
 *
 * `WFMenu::getMenus()` renvoie des objets ORM ; la frontière de valeurs du
 * langage n'accepte que des scalaires et des tableaux. L'action projette donc
 * chaque menu et chaque entrée en map simple, en conservant la hiérarchie par
 * `parentId`.
 */
final class ListMenusAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        try {
            /** @var WFMenu $workflow */
            $workflow = $this->getSharedExtensionsClass(WFMenu::class);

            $spaces = [];

            foreach ($workflow->getMenus() as $spaceId => $menus) {
                $projected = [];

                foreach ($menus as $menu) {
                    $items = [];

                    foreach ($menu->items ?? [] as $item) {
                        $items[] = self::item($item);
                    }

                    $projected[] = [
                        'id' => (int) $menu->id,
                        'spaceId' => (int) $menu->space_id,
                        'name' => (string) $menu->name,
                        'items' => $items,
                    ];
                }

                $spaces[] = ['spaceId' => (int) $spaceId, 'menus' => $projected];
            }

            return ['spaces' => $spaces, 'count' => count($spaces)];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }

    /**
     * Projection d'une entrée de menu.
     *
     * @return array<string, mixed>
     */
    public static function item(object $item): array
    {
        return [
            'id' => (int) $item->id,
            'menuId' => (int) $item->menu_id,
            'parentId' => $item->parent_id === null ? null : (int) $item->parent_id,
            'title' => (string) $item->title,
            'extension' => (string) $item->extension,
            'type' => (string) $item->type,
            'params' => is_array($item->params) ? $item->params : (array) ($item->params ?? []),
            'alias' => (string) $item->alias,
            'icon' => (string) $item->icon,
            'css' => (string) $item->css,
            'status' => (int) $item->status,
            'ordering' => (int) $item->ordering,
            'displayInMenu' => (bool) $item->display_in_menu,
            'browserTitle' => (string) $item->browser_title,
            'metaDescription' => (string) $item->meta_description,
            'metaRobots' => (string) $item->meta_robots,
            'pageTitle' => (string) $item->page_title,
            'accessRoleId' => $item->access_role_id === null ? null : (int) $item->access_role_id,
        ];
    }
}
