<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFMedia;

/** Suppression d'un fichier ou dossier de médias. */
final class DeleteMediaAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $spaceId = ValueTools::assertInt($params['spaceId'] ?? null, $context->span);
        $path = ValueTools::assertString($params['path'] ?? null, $context->span);

        try {
            /** @var WFMedia $workflow */
            $workflow = $this->getSharedExtensionsClass(WFMedia::class);

            return ['deleted' => $workflow->delete($spaceId, $path)];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
