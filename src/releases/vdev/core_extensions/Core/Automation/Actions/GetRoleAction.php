<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFRole;

/**
 * Lecture d'un rôle précis.
 *
 * Le WF n'expose pas de lecture isolée : on filtre le répertoire, déjà borné
 * aux Spaces que l'appelant peut lire. Aucun accès privilégié n'est contourné.
 */
final class GetRoleAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $roleId = ValueTools::assertInt($params['roleId'] ?? null, $context->span);

        try {
            /** @var WFRole $workflow */
            $workflow = $this->getSharedExtensionsClass(WFRole::class);

            foreach ($workflow->getDirectory()['roles'] ?? [] as $role) {
                if ((int) $role['id'] === $roleId) {
                    return $role;
                }
            }

            throw new \RuntimeException('Rôle introuvable ou non accessible.');
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
