<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFUserGroup;

/**
 * Lecture d'un groupe précis.
 *
 * Filtre le répertoire, déjà borné aux Spaces lisibles par l'appelant.
 */
final class GetGroupAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $groupId = ValueTools::assertInt($params['groupId'] ?? null, $context->span);

        try {
            /** @var WFUserGroup $workflow */
            $workflow = $this->getSharedExtensionsClass(WFUserGroup::class);

            foreach ($workflow->getDirectory()['groups'] ?? [] as $group) {
                if ((int) $group['id'] === $groupId) {
                    return $group;
                }
            }

            throw new \RuntimeException('Groupe introuvable ou non accessible.');
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
