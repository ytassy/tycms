<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFUserGroup;

/**
 * Création d'un groupe d'utilisateurs.
 *
 * `parentId` organise les groupes en arborescence ; les droits d'un groupe
 * parent s'appliquent à ses enfants. Les rôles se lisent avec `core::listRoles`
 * et doivent appartenir au même Space.
 */
final class CreateGroupAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $spaceId = ValueTools::assertInt($params['spaceId'] ?? null, $context->span);
        $name = ValueTools::assertString($params['name'] ?? null, $context->span);
        $description = ValueTools::assertString($params['description'] ?? '', $context->span);
        $parentId = isset($params['parentId']) ? ValueTools::assertInt($params['parentId'], $context->span) : 0;
        $roleIds = ValueTools::assertArray($params['roleIds'] ?? [], $context->span);

        try {
            /** @var WFUserGroup $workflow */
            $workflow = $this->getSharedExtensionsClass(WFUserGroup::class);

            return $workflow->createGroup((object) [
                'space_id' => $spaceId,
                'parent_id' => $parentId,
                'name' => $name,
                'description' => $description,
                'role_ids' => $roleIds,
            ]);
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
