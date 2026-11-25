<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFRole;

/**
 * Création d'un rôle dans un Space.
 *
 * `type` : 0 = rôle global, 1 = rôle propre au Space, 2 = rôle système. Les
 * permissions se lisent avec `core::listPermissions` ; le WF refuse un
 * identifiant inconnu ou une dépendance manquante.
 */
final class CreateRoleAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $spaceId = ValueTools::assertInt($params['spaceId'] ?? null, $context->span);
        $type = ValueTools::assertInt($params['type'] ?? null, $context->span);
        $name = ValueTools::assertString($params['name'] ?? null, $context->span);
        $description = ValueTools::assertString($params['description'] ?? '', $context->span);
        $permissions = ValueTools::assertArray($params['permissions'] ?? [], $context->span);

        try {
            /** @var WFRole $workflow */
            $workflow = $this->getSharedExtensionsClass(WFRole::class);

            return $workflow->createRole((object) [
                'space_id' => $spaceId,
                'type' => $type,
                'name' => $name,
                'description' => $description,
                'permissions' => $permissions,
            ]);
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
