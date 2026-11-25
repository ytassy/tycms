<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Core\WF\WFRole;

/**
 * Rôles et leurs permissions.
 *
 * Toute la validation métier reste dans `WFRole` : droits de l'appelant, rôles
 * système protégés, permissions inconnues, dépendances entre permissions et
 * anti-escalade. Ces actions ne font que convertir les valeurs du langage.
 */
final class ListRolesAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        try {
            /** @var WFRole $workflow */
            $workflow = $this->getSharedExtensionsClass(WFRole::class);
            $directory = $workflow->getDirectory();

            return [
                'roles' => $directory['roles'] ?? [],
                'count' => count($directory['roles'] ?? []),
            ];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
