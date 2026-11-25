<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFUserGroup;

/**
 * Attribution des rôles d'un groupe.
 *
 * Remplace l'ensemble des rôles du groupe. Les groupes Invité et Utilisateurs
 * ont des rôles fixes : leurs droits se modifient via les permissions du rôle
 * système correspondant. L'anti-escalade est appliquée par le WF.
 */
final class SetGroupRolesAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $groupId = ValueTools::assertInt($params['groupId'] ?? null, $context->span);
        $roleIds = ValueTools::assertArray($params['roleIds'] ?? [], $context->span);

        try {
            /** @var WFUserGroup $workflow */
            $workflow = $this->getSharedExtensionsClass(WFUserGroup::class);

            return $workflow->updateRoleAssignments($groupId, $roleIds);
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
