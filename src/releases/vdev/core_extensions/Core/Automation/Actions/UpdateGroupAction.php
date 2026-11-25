<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFUserGroup;

/**
 * Modification d'un groupe existant.
 *
 * `roleIds` est facultatif : sans lui, les rôles du groupe sont laissés tels
 * quels. Le WF refuse les groupes système et les parents d'un autre Space.
 */
final class UpdateGroupAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $groupId = ValueTools::assertInt($params['groupId'] ?? null, $context->span);
        $name = ValueTools::assertString($params['name'] ?? null, $context->span);
        $description = ValueTools::assertString($params['description'] ?? '', $context->span);
        $parentId = isset($params['parentId']) ? ValueTools::assertInt($params['parentId'], $context->span) : 0;

        $data = (object) [
            'parent_id' => $parentId,
            'name' => $name,
            'description' => $description,
        ];

        // `role_ids` n'est transmis que s'il est demandé : son absence
        // conserve les attributions existantes.
        if (array_key_exists('roleIds', $params)) {
            $data->role_ids = ValueTools::assertArray($params['roleIds'], $context->span);
        }

        try {
            /** @var WFUserGroup $workflow */
            $workflow = $this->getSharedExtensionsClass(WFUserGroup::class);

            return $workflow->updateGroup($groupId, $data);
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
