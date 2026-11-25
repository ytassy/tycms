<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFUserGroup;

/**
 * Suppression d'un groupe.
 *
 * Refusée si le groupe porte encore des adhésions, des droits ou est référencé
 * par un Space. Le WF vérifie ces usages avant de supprimer.
 */
final class DeleteGroupAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $groupId = ValueTools::assertInt($params['groupId'] ?? null, $context->span);

        try {
            /** @var WFUserGroup $workflow */
            $workflow = $this->getSharedExtensionsClass(WFUserGroup::class);

            return ['deleted' => $workflow->deleteGroup($groupId)];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
