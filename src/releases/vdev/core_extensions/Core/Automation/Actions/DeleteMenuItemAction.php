<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFMenu;

/** Suppression d'une entrée de menu.
 *
 * L'entrée doit d'abord être placée dans la corbeille (statut -2), et ne doit
 * plus être référencée par la navigation du Space. Ces règles sont vérifiées
 * par le WorkFlow. */
final class DeleteMenuItemAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $menuItemId = ValueTools::assertInt($params['menuItemId'] ?? null, $context->span);

        try {
            /** @var WFMenu $workflow */
            $workflow = $this->getSharedExtensionsClass(WFMenu::class);

            return ['deleted' => $workflow->deleteMenuItem($menuItemId)];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
