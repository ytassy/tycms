<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFMenu;

/** Création d'un menu dans un Space.
 *
 * Le menu est un conteneur d'entrées ; les entrées se créent ensuite par type
 * (`createPageMenuItem`, `createTextMenuItem`, `createSeparatorMenuItem`). */
final class CreateMenuAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $spaceId = ValueTools::assertInt($params['spaceId'] ?? null, $context->span);
        $name = ValueTools::assertString($params['name'] ?? null, $context->span);
        $alias = ValueTools::assertString($params['alias'] ?? '', $context->span);
        $description = ValueTools::assertString($params['description'] ?? '', $context->span);

        try {
            /** @var WFMenu $workflow */
            $workflow = $this->getSharedExtensionsClass(WFMenu::class);

            return ['created' => true, 'id' => (int) $workflow->createMenu((object) [
                'space_id' => $spaceId,
                'name' => $name,
                'alias' => $alias,
                'description' => $description,
            ])->id];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
