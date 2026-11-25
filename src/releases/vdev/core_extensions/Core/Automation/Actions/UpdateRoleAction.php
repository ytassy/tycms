<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFRole;

/**
 * Modification d'un rôle existant.
 *
 * Le nom, la description et la liste complète des permissions sont fournis :
 * la modification remplace l'ensemble, elle ne fusionne pas. Les rôles système
 * ne laissent éditer que leurs permissions, et seul un super administrateur
 * peut y toucher ; le WF applique ces règles.
 */
final class UpdateRoleAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $roleId = ValueTools::assertInt($params['roleId'] ?? null, $context->span);
        $name = ValueTools::assertString($params['name'] ?? null, $context->span);
        $description = ValueTools::assertString($params['description'] ?? '', $context->span);
        $permissions = ValueTools::assertArray($params['permissions'] ?? [], $context->span);

        try {
            /** @var WFRole $workflow */
            $workflow = $this->getSharedExtensionsClass(WFRole::class);

            return $workflow->updateRole($roleId, (object) [
                'name' => $name,
                'description' => $description,
                'permissions' => $permissions,
            ]);
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
