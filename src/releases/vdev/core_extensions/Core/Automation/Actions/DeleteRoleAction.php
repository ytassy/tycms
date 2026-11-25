<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFRole;

/**
 * Suppression d'un rôle.
 *
 * Refusée pour un rôle système, un rôle encore attribué à un groupe, ou un
 * rôle référencé par un menu, une page ou un Space. Ces règles vivent dans le
 * WF et produisent un message explicite.
 */
final class DeleteRoleAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $roleId = ValueTools::assertInt($params['roleId'] ?? null, $context->span);

        try {
            /** @var WFRole $workflow */
            $workflow = $this->getSharedExtensionsClass(WFRole::class);

            return ['deleted' => $workflow->deleteRole($roleId)];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
