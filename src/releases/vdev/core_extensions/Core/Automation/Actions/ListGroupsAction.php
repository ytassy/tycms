<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Core\WF\WFUserGroup;

/**
 * Groupes d'utilisateurs et leurs rôles.
 *
 * Les groupes personnels sont masqués et les groupes système protégés par le
 * WF. Deux permissions distinctes s'appliquent : la gestion du groupe
 * (`USER_GROUPS_CRUD`) et l'attribution de ses rôles (`GROUP_ROLES_ASSIGN`).
 */
final class ListGroupsAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        try {
            /** @var WFUserGroup $workflow */
            $workflow = $this->getSharedExtensionsClass(WFUserGroup::class);
            $directory = $workflow->getDirectory();

            return [
                'groups' => $directory['groups'] ?? [],
                'count' => count($directory['groups'] ?? []),
                'spaces' => $directory['spaces'] ?? [],
            ];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
