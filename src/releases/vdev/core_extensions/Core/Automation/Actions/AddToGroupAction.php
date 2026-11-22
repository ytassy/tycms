<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\Automation\Support\UserPayload;
use YTassy\Tycms\CoreExts\Core\WF\WFAdminUser;

final class AddToGroupAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $userId = ValueTools::assertInt($params['userId'] ?? null, $context->span);
        $groupId = ValueTools::assertInt($params['groupId'] ?? null, $context->span);

        try {
            /** @var WFAdminUser $workflow */
            $workflow = $this->getSharedExtensionsClass(WFAdminUser::class);

            return UserPayload::map($workflow->setGroup($userId, $groupId, true));
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
