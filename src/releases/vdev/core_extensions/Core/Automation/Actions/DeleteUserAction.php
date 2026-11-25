<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFAdminUser;

/**
 * Suppression d'un compte utilisateur.
 *
 * Le WF protège les comptes ROOT et les références encore ouvertes ; ces
 * refus remontent avec un message explicite.
 */
final class DeleteUserAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $userId = ValueTools::assertInt($params['userId'] ?? null, $context->span);

        try {
            /** @var WFAdminUser $workflow */
            $workflow = $this->getSharedExtensionsClass(WFAdminUser::class);

            return ['deleted' => $workflow->deleteUser($userId)];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
