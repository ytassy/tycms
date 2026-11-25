<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFMenu;

/** Modification du nom, de l'alias ou de la description d'un menu.
 *
 * Le Space d'un menu existant n'est pas modifiable. */
final class UpdateMenuAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $menuId = ValueTools::assertInt($params['menuId'] ?? null, $context->span);
        $name = ValueTools::assertString($params['name'] ?? null, $context->span);
        $alias = ValueTools::assertString($params['alias'] ?? '', $context->span);
        $description = ValueTools::assertString($params['description'] ?? '', $context->span);

        try {
            /** @var WFMenu $workflow */
            $workflow = $this->getSharedExtensionsClass(WFMenu::class);

            return ['updated' => true, 'id' => (int) $workflow->updateMenu($menuId, (object) [
                'name' => $name,
                'alias' => $alias,
                'description' => $description,
            ])->id];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
