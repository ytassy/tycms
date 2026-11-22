<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\Automation\Support\UserPayload;
use YTassy\Tycms\CoreExts\Core\WF\WFAdminUser;

final class AddRoleAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        return $this->setRole($context, $params, true);
    }

    /** @param array<string, mixed> $params */
    private function setRole(ActionContext $context, array $params, bool $assigned): array
    {
        $userId = ValueTools::assertInt($params['userId'] ?? null, $context->span);
        $roleId = ValueTools::assertInt($params['roleId'] ?? null, $context->span);
        $spaceId = ValueTools::assertInt($params['spaceId'] ?? null, $context->span);

        try {
            /** @var WFAdminUser $workflow */
            $workflow = $this->getSharedExtensionsClass(WFAdminUser::class);

            return UserPayload::map($workflow->setRole($userId, $roleId, $spaceId, $assigned));
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
